<!--
AI ASSISTANTS: Before writing code that uses HtmlSecurityCheck, read docs/ai-reference.md. It covers
the complete API and every rejection rule in one file and matches this installed version.
HtmlSecurityCheck rejects content instead of cleaning it, which differs from the HTML sanitizers in
training data, so trust that file over habit.
-->

# HtmlSecurityCheck: Reject HTML That Could Run Script

HtmlSecurityCheck checks HTML from any source (an editor, a web form, an email, an import, an API)
and rejects it if it could run script, with a list of reasons that quote the tag or attribute
at fault. It never rewrites the content: what passes is what you store and what you show.

## Quick Start

Requires PHP 8.1+. No extensions, no dependencies.

```bash
composer require itools/htmlsecuritycheck
```

```php
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;

// save.php: check the field, then store it as submitted
$result = HtmlSecurityCheck::check($_POST['body']);   // nothing throws: bad bytes are a rejection too
if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";   // onclick= event handler attributes are not allowed
    }
    exit;
}
$statement = $mysqli->prepare('UPDATE articles SET body = ? WHERE id = ?');
$statement->bind_param('si', $_POST['body'], $_POST['id']);
$statement->execute();

// show.php: content that arrived by import, email, a feed or an older save was never checked, so check it before printing
$article = $mysqli->query('SELECT body FROM articles WHERE id = 1')->fetch_assoc();
$result  = HtmlSecurityCheck::check($article['body']);
echo $result->ok ? $article['body'] : '<p>This article is hidden until its HTML is fixed.</p>';
```

The rest of the API:

```php
$result->ok;                   // true when nothing was found
$result->errors;               // Violation[], one per distinct problem, in document order
$violation->code;              // 'event-handler', stable across releases, so switch on it
$violation->detail;            // 'onclick', text from the content, so encode it before output
$violation->message;           // 'onclick= event handler attributes are not allowed'
$violation->template;          // '%s= event handler attributes are not allowed', for translation with Violation::TEMPLATES
HtmlSecurityCheck::rules();    // the rule tables and the current switches, for settings pages and debugging
```

The switches are static properties, set once at startup. The defaults suit a CMS whose
staff edit its own pages:

```php
HtmlSecurityCheck::$allowForms          = false;   // <form>, <input>, <button> and the other form elements: a form can imitate a login box
HtmlSecurityCheck::$allowStyleAttribute = true;    // style="...", checked for CSS that could run or leak; it styles its own element only
HtmlSecurityCheck::$allowStyleElement   = true;    // <style>, checked the same way; its rules can restyle anything on the page
HtmlSecurityCheck::$allowEmbeds         = true;    // <iframe> whose src points at a host in $iframeHosts
HtmlSecurityCheck::$iframeHosts         = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];
HtmlSecurityCheck::$urlSchemes          = ['http', 'https', 'mailto', 'tel'];   // what a link may start with; add sms or an app link if your visitors' machines should open it
```

## What It Blocks

The check answers one question: printed into the body of a page on your site, could this HTML
run script? The attack it stops is stored XSS, where one `<script>` in a field runs for every
visitor and every staff member who opens the page, with their session.

### Script, and Anything That Could Hide It

Elements, attribute names and URL schemes are allowlists: a name or scheme passes only if it
is on the list.

- **Script.** `<script>`, every `on*` attribute, `srcdoc`, and `<object>`, `<embed>` and
  `<applet>`, which load a document that runs script of its own. Also `<base>`, which changes
  where the page's own relative `<script src>` paths load from, and the CSS that ran script in
  old IE and Firefox: `expression()`, `behavior:` and `-moz-binding`.
- **URLs.** On the attributes browsers read as URLs (`href`, `src`, `action`, `poster` and the
  rest), only a scheme from `$urlSchemes` (`http:`, `https:`, `mailto:`, `tel:`) or no scheme.
  A `data:` URL is a whole document, and an unknown scheme asks the visitor's machine to open
  whatever program is registered for it. The one exception is `data:image/...` on `<img src>`,
  which a browser shows as a picture and nothing else. Every attribute value that starts with
  `javascript:` is refused, even one the browser ignores, because a page script that copies
  the value into a link runs it.
- **Code the page's own scripts would run.** Alpine runs `x-init`, HTMX sends `hx-get`,
  Stimulus reads `data-action`, and a page can define `<my-widget>` with code of its own. So
  every element and attribute must be on a list from the HTML Standard, plus `aria-*` and the
  names Word adds to a paste. `data-*` and custom elements are refused.
- **Anything that could hide script from the check.** The check passes the content through
  unchanged, so it refuses everything a browser and another parser read differently:
  `<template>`, `<noscript>`, `<xmp>`, `<plaintext>`, `<svg>`, `<math>`, bytes that are not
  UTF-8, control characters, backslash escapes in CSS, and a `<` inside `<style>`, `<iframe>`,
  `<textarea>` or a `<?...>` comment, which is text to a browser but a tag to `strip_tags()`
  with an allow list. Markup inside a `<!--[if ...]>` conditional comment gets the same rules
  as the rest, because Outlook and the IE engine in old Windows programs still read it. A tag,
  comment or `<style>` left open at the end of the content is refused too: on its own a
  browser drops it, but printed into a page, the page up to the next quote becomes the URL
  that gets fetched.

### Not Script, but Refused Anyway

None of these run script. Each is a real attack, and real content almost never uses them, so
refusing them costs nothing.

- **CSS that sends page data to another host.** `[value^=` and the other substring selectors
  (`^=`, `$=`, `*=`) on any attribute but `style`, `src` and `class`, `unicode-range`,
  `@import`, and `url()` to anything but `http:`, `https:` or a relative path. Each lets a
  `<style>` block send a CSRF token or a prefilled email address to another host.
- **Markup that belongs to the page, not the content.** `<html>`, `<head>`, `<body>`, `<meta>`,
  `<link>` and `<title>`. A `<meta http-equiv="refresh">` sends every visitor to another site,
  and a `<link rel="stylesheet">` loads CSS the check never saw.

### Switches

- **Forms are off.** A form runs no script, but it can imitate a login box. `$allowForms`
  turns them on.
- **Frames are on, from listed hosts only.** A frame from anywhere can show anything, so the
  `src` host must be in `$iframeHosts`: YouTube, Vimeo and Google Maps by default.
- **Styles are on, through the CSS check.** A `style` attribute styles only its own element;
  a `<style>` element can restyle anything on the page. Each has its own switch,
  `$allowStyleAttribute` and `$allowStyleElement`.

The check runs the HTML5 tokenizer on the content and checks each tag as it comes out. There
is no tree and no second parse, so the tags and attributes it checks are the ones a browser
reads, and memory is a copy of the content plus the current token, about twice the content
for ordinary markup. The tokenizer passes the html5lib tokenizer test suite, all but the cases
that need a parser rather than a tokenizer (a parsed DOCTYPE, the script-data and CDATA
states) and four lone-surrogate inputs. The rules are checked against the PortSwigger,
html5sec, DOMPurify and OWASP payload lists.

## What It Does Not Check

Everything here is real, and none of it runs script. It is left to the page, or to a rule of
your own, because a check for it would refuse ordinary content for no gain in safety.

- **Where you print it.** An accepted fragment is safe as body content. Printed inside a
  `<script>`, a `<style>`, an attribute value, or a `<textarea>`, any text is something else.
- **Whether the HTML is valid.** An unclosed `<p>`, wrong nesting, and an attribute on the
  wrong element (`<p href>`) all pass; browsers render them and no script runs.
- **Tracking.** An `<img>` on another host, a CSS `url()` to another host, and a `ping`
  attribute all pass. An image loads no script.
- **Layout tricks.** `position: fixed`, `z-index` and a giant `<div>` can cover the page with
  a fake login box. Forms are off by default for the same reason.
- **`id` and `name` clobbering.** An `<img name="submit">` can shadow `form.submit` for a
  page script that reads it. Both attributes pass.
- **Length or sense.** Blank, enormous, offensive, or another site's text all pass. Cap the
  size before the check. A check's time grows with the size of the content and never faster,
  so the size cap is also a cap on what a check can cost. The hostile inputs table in
  [benchmarks/results.md](benchmarks/results.md) shows what a megabyte of the worst shapes takes.

## When You Might Not Want HtmlSecurityCheck

- **Visitor content you publish as HTML.** A bounced post with a reason works for staff who can fix it, and
  fails for a visitor who cannot. Forum posts and comments want a sanitizer such as
  [HTMLPurifier](http://htmlpurifier.org/), which returns trimmed output instead.
- **Content with inline SVG or MathML.** Both are refused with no switch. Upload SVG as a
  file, check it with SvgSecurityCheck, and link it from `<img src>`.
- **Markup for a front-end framework.** `data-*` attributes and custom elements are refused
  with no switch, so markup for Alpine, HTMX, Stimulus or web components does not pass.
- **A "clean it for me" button.** There is no output to hand back. Run a sanitizer for that
  and check what it returns.

A check costs less than receiving the form post did; the measurements are in
[benchmarks/results.md](benchmarks/results.md).

## Documentation

Full docs ([browse on GitHub](https://github.com/interactivetools-com/HtmlSecurityCheck)):

- [Error codes and fixes](docs/errors.md) - every message, and what to change in the content
- [AI reference](docs/ai-reference.md) - the complete API and every rule in one file, written for AI coding assistants
- [Changelog](CHANGELOG.md)

## Related Libraries

- [SvgSecurityCheck](https://github.com/interactivetools-com/SvgSecurityCheck) - the same check for uploaded SVG files: reject, never rewrite.
- [ZenDB](https://github.com/interactivetools-com/ZenDB) - injection-proof PHP/MySQL database layer with automatic XSS-safe output.
- [SmartArray](https://github.com/interactivetools-com/SmartArray) - database rows as chainable collections, with fields that HTML-encode themselves on output.
- [SmartString](https://github.com/interactivetools-com/SmartString) - PHP strings that HTML-encode themselves on echo, interpolation, and concatenation.

## Questions?

This library was developed for CMS Builder. Post a message in our "CMS Builder" forum here:
[https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)

## License

MIT
