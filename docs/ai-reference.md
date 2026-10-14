# HtmlSecurityCheck AI Reference

This is a consolidated reference for AI coding assistants: the complete API and every
rejection rule in one file, covering HtmlSecurityCheck 1.0. For human-friendly docs, see the
[README](https://github.com/interactivetools-com/HtmlSecurityCheck).

Contents:

- [What HtmlSecurityCheck Is](#what-htmlsecuritycheck-is)
- [API](#api) - check(), rules(), the switches, Result, Violation
- [Usage Pattern](#usage-pattern)
- [Error Codes](#error-codes)
- [What Is Refused](#what-is-refused) - every rule in short, Word pastes, fixing a rejection
- [Rule Tables](#rule-tables)
- [Limits](#limits)
- [Constraints and Gotchas](#constraints-and-gotchas)

---

## What HtmlSecurityCheck Is

HtmlSecurityCheck checks rich-text HTML (WYSIWYG output, HTML fields, API input) and rejects it if
it could run script in the page it is printed into, send the page's data to another host, or
mislead the visitor, **with a list of reasons**. The content is **never modified**: there is
no cleaned output, and the caller stores the original string or refuses the save.

It runs the HTML5 tokenizer on the content and checks each start tag as it comes out. No tree
is built, so the tags and attributes it checks are the ones a browser reads from the same
bytes.

```php
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;

$result = HtmlSecurityCheck::check($_POST['body']);
$result->ok;       // true when the content passed
$result->errors;   // Violation[] - empty when ok, otherwise one per distinct problem
```

Requirements: PHP 8.1+. No extensions, no dependencies.

## API

Three public classes in the `Itools\HtmlSecurityCheck` namespace. Nothing throws for bad content:
a hostile or broken string is expected input and comes back as a rejected `Result`.

```php
HtmlSecurityCheck::check(string $html): Result   // time grows with the size of the content, nothing else; memory is a copy of the string plus the current token
HtmlSecurityCheck::rules(): array                // the rule tables and the current switch values, for settings pages and debugging
```

`rules()` returns an array with the keys `elements`, `formElements`, `attributes`,
`attributePrefixes`, `officeNamespaces`, `formAttributes`, `urlSchemes`, `scriptSchemes`,
`cssUrlSchemes`, `cssFunctions`, `cssAtRules`, `urlAttributes`, `allowForms`, `allowStyleAttribute`, `allowStyleElement`,
`allowEmbeds` and `iframeHosts`. See [Rule Tables](#rule-tables).
The keys are copies of the constants and the current switch values; changing the returned
array changes nothing.

### Switches

Public static properties, read by every `check()` call. Set them once at startup.

```php
HtmlSecurityCheck::$allowForms          = false;   // form elements (FORM_ELEMENTS) and formaction pass
HtmlSecurityCheck::$allowStyleAttribute = true;    // the style attribute passes, after the CSS check; it styles its own element only
HtmlSecurityCheck::$allowStyleElement   = true;    // the <style> element passes, after the CSS check; its rules can style anything on the page
HtmlSecurityCheck::$allowEmbeds         = true;    // <iframe> passes when its src host is in $iframeHosts
HtmlSecurityCheck::$iframeHosts         = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];
HtmlSecurityCheck::$urlSchemes          = ['http', 'https', 'mailto', 'tel'];   // schemes a URL attribute may start with
HtmlSecurityCheck::$maxErrors       = 50;   // distinct errors reported per check
HtmlSecurityCheck::$maxDetailLength = 80;   // characters of content quoted in a detail before "..."
HtmlSecurityCheck::$fastPath        = true; // text and tags the rules can never refuse are stepped over in one regex match; false sends every byte through the tokenizer, same result, slower
```

`$iframeHosts` entries are host names only, matched whole and case-insensitively: no scheme,
no port, no path, no wildcard. `player.vimeo.com` does not match `vimeo.com` or the other way
round.

`$urlSchemes` entries are scheme names with no colon, read case-insensitively. Add a scheme
when the visitor's machine should open it on a click (`sms`, `whatsapp`, an app link): every
scheme past the four defaults hands the click to whatever program is registered for it.
`javascript` is refused whatever the list holds, so a wrong entry cannot turn the check off.

### Result

```php
final class Result
{
    public readonly bool  $ok;       // true when $errors is empty
    public readonly array $errors;   // Violation[], in document order, at most $maxErrors
}
```

`errors` holds one `Violation` per distinct problem (deduplicated by code and detail), in the
order found. `not-utf8` and `control-character` stop the check before tokenizing, so either
arrives alone. `unclosed-markup`, when present, is always the last entry.

### Violation

```php
$violation->code;       // 'event-handler' - stable across releases
$violation->detail;     // 'onclick' - plain text taken from the content, at most 80 characters plus '...'
$violation->template;   // '%s= event handler attributes are not allowed'
$violation->message;    // 'onclick= event handler attributes are not allowed'
Violation::TEMPLATES;   // every message template keyed by code, one %s each
```

All four properties are readonly strings.

`detail` and `message` contain text from the content. HTML-encode them before output.

For translation, `template` goes through the translation function and `detail` goes back in
with `sprintf()`. Details hold literal `<`, `>` and quotes, so the whole result is encoded:

```php
echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));
```

`Violation::TEMPLATES` lists every template by code so a translation system can register
them all up front.

## Usage Pattern

```php
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;

$result = HtmlSecurityCheck::check($_POST['body']);

if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo '<p>', htmlspecialchars($violation->message), '</p>';
    }
    exit;
}
$statement = $mysqli->prepare('UPDATE articles SET body = ? WHERE id = ?');
$statement->bind_param('si', $_POST['body'], $_POST['id']);   // the original string, unchanged
$statement->execute();
```

HTML sanitizers return a cleaned string to store. HtmlSecurityCheck does not; store the original.

```php
// WRONG - check() returns a Result, not cleaned HTML
$clean = HtmlSecurityCheck::check($html);
$statement->bind_param('si', $clean, $id);

// RIGHT - check, then store the original string only when ok
if (HtmlSecurityCheck::check($html)->ok) {
    $statement->bind_param('si', $html, $id);
}
```

Switch on `code`, not on `message`, when the application reacts to a specific rule. Codes
never change once released; message wording can.

## Error Codes

Every code, its template, and what `detail` holds. Templates are `Violation::TEMPLATES`.

| Code                     | Template                                                                                        | `detail`                                                                                                                                                  |
|--------------------------|-------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| `not-utf8`               | `Content must be UTF-8, this is not: %s`                                                        | the 16 bytes from the first invalid byte, non-UTF-8 bytes escaped as octal (`\351`), then `...` when more follows                                         |
| `control-character`      | `Content contains a control character: %s`                                                      | the character escaped (`\000`, `\033`, `\f`, `\v`) and ` at byte N`, for the first one found                                                             |
| `element-not-allowed`    | `%s is not allowed`                                                                             | the start tag as written, from `<` to `>` (`<script src="x">`), or the doctype (`<!DOCTYPE html>`)                                                        |
| `event-handler`          | `%s= event handler attributes are not allowed`                                                  | the attribute name, lowercased (`onclick`)                                                                                                                |
| `attribute-not-allowed`  | `The %s attribute is not allowed`                                                               | the attribute name, lowercased (`data-id`, `x-init`, `srcdoc`), or for an `xmlns:*` declaration the name and value (`xmlns:o="http://www.w3.org/1999/xhtml"`) |
| `url-scheme-not-allowed` | `%s uses a URL scheme that is not allowed`                                                      | the attribute name and its decoded value (`href="javascript:alert(1)"`)                                                                                   |
| `iframe-host`            | `Embedding frames from %s is not allowed`                                                       | the `src` value with whitespace removed (`https://evil.example/x`), or `(no src)`                                                                         |
| `css-not-allowed`        | `CSS containing %s is not allowed`                                                              | the function or at-rule as written (`expression(`, `@import`), the banned token as matched (`\`, `[value^=`, `unicode-range`) or the `url()` with its target (`url(javascript:x)`), or the first 80 characters of a block past the PCRE limit (megabytes of `x*|` pairs inside one `[`) |
| `less-than-in-text`      | `A < where a browser reads text, not tags: %s`                                                  | the text from that `<` to the end of the raw text or comment (`<img src=x onerror=alert(1)>`, `<b> c`)                                                    |
| `unclosed-markup`        | `%s is not closed`                                                                              | the unfinished markup from where it opened to the end of the content (`<img src="//evil.example/?`, `<!-- hidden`, `<style>p { }`)                        |

Details longer than 80 characters are cut at 80 and end with `...`. Newlines and other
control characters inside a detail are escaped (`\n`), so a detail is always one line.

## What Is Refused

Element names, attribute names, iframe hosts and the schemes on URL attributes are
allowlists: a name the check does not know is refused. The exact rules are the constants and
comments in `src/HtmlSecurityCheck.php`; the [Rule Tables](#rule-tables) are generated from
them.

- **Elements.** `ELEMENTS` pass. `<style>` passes while `$allowStyleElement` is set,
  `<iframe>` while `$allowEmbeds` is set and its host is listed, `FORM_ELEMENTS` while
  `$allowForms` is set, and Word's `o:`, `v:` and `w:` names always. Everything else is
  refused on purpose: `script`, `noscript`, `template`, `svg`, `math`, `object`, `embed`,
  `applet`, `base`, `link`, `meta`, `html`, `head`, `body`, `title`, and custom
  elements (`<my-widget>`), which a page script can define. A refused tag is one error; its
  attributes are not checked.
- **Attributes.** A name starting with `on` is `event-handler`. A name passes when it is in
  `ATTRIBUTES`, starts with an entry in `ATTRIBUTE_PREFIXES` (`aria-`, `o:`, `v:`, `w:`), is
  an `xmlns:*` whose value starts with an entry in `OFFICE_NAMESPACES`, or is in
  `FORM_ATTRIBUTES` while `$allowForms` is set. Refused with no switch: `data-*`, `srcdoc`,
  `is`, and framework directives (`x-init`, `@click`, `hx-get`), since a page's own
  JavaScript can run code from them.
- **URLs.** On `URL_ATTRIBUTES` a value must have no scheme or one in `$urlSchemes`; every URL
  in a `srcset` or `ping` list is checked. The scheme is read as a browser reads it, entities
  decoded and whitespace dropped, so `jav&#x09;ascript:` is `javascript:`. `data:image/...`
  passes on `<img src>` only. Every other attribute value is refused only when it starts with
  a scheme in `SCRIPT_SCHEMES` (`javascript:`).
- **Iframes.** `src` must be `https://`, `http://` or `//`, then a host in `$iframeHosts`
  matched whole (`player.vimeo.com` is not `vimeo.com`; no port, no subdomains), then the
  end or `/`, `?` or `#`. Otherwise `iframe-host`.
- **CSS**, in `style` attributes and `<style>` text. Function names must be in
  `CSS_FUNCTIONS` and at-rules in `CSS_AT_RULES` (lists below), so `image-set(`,
  `expression(` and `@import` are refused. Also refused: `\`, `behavior:`, `-ms-behavior:`,
  `-moz-binding`, `unicode-range`, and `[attr^=`, `$=` or `*=` on any attribute but `style`,
  `src` and `class`. `url()` must be relative or in `CSS_URL_SCHEMES` (`http`, `https`).
  Comments and quoted strings are skipped. Property names are not checked: `position: fixed`
  and `mso-*` pass.
- **Parser differences.** Invalid UTF-8 or a control character other than tab, LF and CR is
  the only error reported. A `<` inside `<style>`, `<iframe>`, `<textarea>` or a comment that
  is not `<!-- -->` (`<?php ?>`, `<!x>`) is `less-than-in-text`; a `<!--` inside `<style>`
  passes when its `-->` is in the same block. A tag, quoted value,
  comment or `<style>` left open at the end is `unclosed-markup`, always the last error.
- **Not checked.** End tags, text and comments, except the markup inside a
  `<!--[if ...]> ... <![endif]-->` conditional comment, which gets the same rules as the rest.

### Word and Email Pastes

Pass: `class="MsoNormal"`, `mso-*` styles, `<o:p>`, `<v:shape>`, `xmlns:o` with an Office
namespace, `<?xml:namespace ...>`, Word's `<!--[if gte mso 9]><xml>...</xml><![endif]-->`
settings block, its `<style><!-- ... --></style>`, and
`<!--[if mso]><table>...</table><![endif]-->`. Refused: a whole pasted page (`<html>`,
`<head>`, `<body>`), images at `file:` or `blob:` URLs, which exist only on the editor's
computer, and Word's `v\:* {behavior:url(#default#VML)}` rule for shapes.

### Fixing a Rejection

The fix is in the content: [docs/errors.md](https://github.com/interactivetools-com/HtmlSecurityCheck/blob/main/docs/errors.md)
gives it for every code. Do not strip the refused tags with a regex and save the result, add
`data-*` values back under another name, or turn the check off for one user; tell the editor
what the message says. For `data-*`, use a `class` or `id`, and change the page script that
read the value.

## Rule Tables

The lists as `rules()` returns them, plus the current switch values. Generated from the code
by `tools/rules-doc.php`.

**`elements`** (pass with no switch):

<!-- rules:elements -->
```text
a abbr acronym address area article aside audio b bdi bdo big blink blockquote br canvas caption
center cite code col colgroup data dd del details dfn dialog dir div dl dt em figcaption figure font
footer h1 h2 h3 h4 h5 h6 header hgroup hr i img ins kbd li listing main map mark marquee menu meter
multicol nav nobr ol p param picture pre progress q rb rp rt rtc ruby s samp search section slot
small source spacer span strike strong sub summary sup table tbody td tfoot th thead time tr track
tt u ul var video wbr xml
```
<!-- /rules:elements -->

**`formElements`** (pass when `allowForms` is set):

<!-- rules:formElements -->
```text
form input button select selectedcontent option optgroup datalist textarea label fieldset legend
output
```
<!-- /rules:formElements -->

**`attributes`** (pass on any element; every other name is `attribute-not-allowed`):

<!-- rules:attributes -->
```text
abbr accept accept-charset accesskey action allow allowfullscreen alpha alt as async autocapitalize
autocomplete autocorrect autofocus autoplay blocking charset checked cite class closedby color
colorspace cols colspan command commandfor content contenteditable controls coords crossorigin data
datetime decoding default defer dir dirname disabled download draggable enctype enterkeyhint
fetchpriority for form formenctype formmethod formnovalidate formtarget headers headingoffset
headingreset height hidden high href hreflang http-equiv id imagesizes imagesrcset inert inputmode
integrity ismap itemid itemprop itemref itemscope itemtype kind label lang list loading loop low max
maxlength media method min minlength multiple muted name nomodule nonce novalidate open optimum
pattern ping placeholder playsinline popover popovertarget popovertargetaction poster preload
readonly referrerpolicy rel required reversed rows rowspan sandbox scope selected shadowrootclonable
shadowrootcustomelementregistry shadowrootdelegatesfocus shadowrootmode shadowrootserializable
shadowrootslotassignment shape size sizes slot span spellcheck src srclang srcset start step style
tabindex target title translate type usemap value width wrap writingsuggestions role xml:lang
xml:space align axis background bgcolor border bordercolor cellpadding cellspacing char charoff
clear compact face frame frameborder hspace longdesc marginheight marginwidth noshade nowrap rules
scrolling summary valign vspace allowtransparency mozallowfullscreen webkitallowfullscreen arcsize
arrowok aspectratio coordsize eqn fill fillcolor filled from gradientshapeok inset joinstyle opacity
path stroke strokecolor stroked strokeweight to docpart prefixmappings sdttag showingplchdr
storeitemid temporary text xpath namespaceuri
```
<!-- /rules:attributes -->

**`attributePrefixes`** (pass with any name after the prefix): <!-- rules:attributePrefixes -->
`aria-`, `o:`, `v:`, `w:`
<!-- /rules:attributePrefixes -->

**`officeNamespaces`** (what an `xmlns:*` value may start with): <!-- rules:officeNamespaces -->
`urn:schemas-microsoft-com:`, `http://schemas.microsoft.com/office/`
<!-- /rules:officeNamespaces -->

**`formAttributes`** (`attribute-not-allowed` unless `allowForms` is set): <!-- rules:formAttributes -->
`formaction`
<!-- /rules:formAttributes -->

**`urlSchemes`** (allowed on a URL attribute; the `$urlSchemes` setting) default: <!-- rules:urlSchemes -->
`http`, `https`, `mailto`, `tel`
<!-- /rules:urlSchemes -->

**`scriptSchemes`** (refused on every attribute): <!-- rules:scriptSchemes -->
`javascript`
<!-- /rules:scriptSchemes -->

**`cssUrlSchemes`** (allowed in a CSS `url()`): <!-- rules:cssUrlSchemes -->
`http`, `https`
<!-- /rules:cssUrlSchemes -->

**`cssFunctions`** (the CSS functions that pass): <!-- rules:cssFunctions -->
```text
url format local rgb rgba hsl hsla linear-gradient radial-gradient conic-gradient
repeating-linear-gradient repeating-radial-gradient repeating-conic-gradient matrix matrix3d
translate translatex translatey translatez translate3d scale scalex scaley scalez scale3d rotate
rotatex rotatey rotatez rotate3d skew skewx skewy perspective blur brightness contrast drop-shadow
grayscale hue-rotate invert opacity saturate sepia circle ellipse inset polygon path rect calc var
min max clamp minmax repeat fit-content counter counters cubic-bezier steps not is where has
nth-child nth-last-child nth-of-type nth-last-of-type lang dir
```
<!-- /rules:cssFunctions -->

**`cssAtRules`** (the CSS at-rules that pass, without the `@`): <!-- rules:cssAtRules -->
`font-face`, `page`, `list`, `media`, `supports`, `keyframes`, `-webkit-keyframes`, `charset`
<!-- /rules:cssAtRules -->

**`urlAttributes`** (values must use `urlSchemes` or no scheme): <!-- rules:urlAttributes -->
`href`, `src`, `action`, `formaction`, `poster`, `ping`, `srcset`, `cite`, `longdesc`, `background`
<!-- /rules:urlAttributes -->

**`allowForms`** default: <!-- rules:allowForms -->
`false`
<!-- /rules:allowForms -->

**`allowStyleAttribute`** default: <!-- rules:allowStyleAttribute -->
`true`
<!-- /rules:allowStyleAttribute -->

**`allowStyleElement`** default: <!-- rules:allowStyleElement -->
`true`
<!-- /rules:allowStyleElement -->

**`allowEmbeds`** default: <!-- rules:allowEmbeds -->
`true`
<!-- /rules:allowEmbeds -->

**`iframeHosts`** default: <!-- rules:iframeHosts -->
`www.youtube.com`, `www.youtube-nocookie.com`, `player.vimeo.com`, `www.google.com`
<!-- /rules:iframeHosts -->

## Limits

Public static properties, set before the check. The rules themselves have three switches and
a host list, see [Switches](#switches).

| Limit                          | Value         | Source              |
|--------------------------------|---------------|---------------------|
| Errors reported per check      | 50            | `$maxErrors`        |
| Value length in `detail`       | 80 characters | `$maxDetailLength`  |
| Content size                   | none          | time grows with size, nothing else; no tree |
| Nesting depth                  | none          | no tree             |

Memory is a copy of the input (line endings normalized) plus the current token. A text run
with no tags is one token, so a 10 MB string of text costs about 20 MB. Cap the size at the
form or the API before the check.

## Constraints and Gotchas

- **No cleaned output.** The result is accept or reject. Store the original string or refuse.
- **No line numbers.** `Violation` has `code` and `detail` only. The detail quotes the tag or
  attribute, which is enough to find it in a field.
- **Same problem once.** Five `<script>` tags report one error; five different `on*`
  attributes report five; two `<script>` tags with different attributes report two, since the
  detail is the whole tag.
- **50 errors and the check stops.** Nothing past the fiftieth distinct problem is reported.
- **A byte error arrives alone.** `not-utf8` and `control-character` stop the check before
  tokenizing.
- **`detail` and `message` are unencoded text from the content.** Always one line of valid
  UTF-8 (control characters and bytes that are not UTF-8 are escaped, as `\n` or `\351`), but
  they can hold `</script>`, quotes and backticks. HTML-encode for a page. A JSON response
  with `Content-Type: application/json` needs nothing extra. Inside a `<script>` block:

  ```php
  $json = json_encode($violation->message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ```
- **Names are lowercased before matching**, so element and attribute rules are
  case-insensitive; URL schemes, hosts and CSS tokens are matched case-insensitively too.
- **The check assumes the content is printed as HTML body content**, between ordinary tags
  such as `<div>` or `<td>`. Printed inside `<script>`, `<style>`, `<textarea>`, `<title>`,
  inline SVG or an attribute value, any text is something else, and the rules do not apply.
- **Not checked:** end tags (a `</div>` in the content can close the page's own wrapper),
  whether the HTML is valid or well nested, `id` and `name` (DOM clobbering), `target`,
  external images and other tracking loads, `position: fixed` and other layout overlays,
  content length, and what the text says.
- **Check stored content before printing it.** HTML from an import, an email or a save from
  before the check was never checked. A check costs under 0.04 ms for a typical page, so
  checking on every view is fine. To show staff a rejected record, print it in
  `<iframe sandbox srcdoc="<?= htmlspecialchars($html) ?>"></iframe>`.
- **Switches are process-wide.** They are static properties, so a change in one request
  handler affects every later `check()` in the same process. Set them once in a file every
  entry point includes, so the save and the page that prints agree. Add a host with
  `HtmlSecurityCheck::$iframeHosts[] = 'media.example.com';` to keep the defaults.
- **`Tokenizer`, `Token` and `CharacterReferences` are internal.** They are HtmlSecurityCheck's
  own parser, marked `@internal`, and can change between releases. The public API is
  `HtmlSecurityCheck`, `Result` and `Violation`.
