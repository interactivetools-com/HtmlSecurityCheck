# HtmlSecurityCheck Rules

The specification: every rule the check enforces, the order it runs them in, and what the
tokenizer sees. The Codex security scan reads this file as the intended behavior, and anyone
changing a rule reads it with `src/HtmlSecurityCheck.php`. The API and the rule tables are in
[docs/ai-reference.md](../ai-reference.md); why each rule exists is in
[design-decisions.md](design-decisions.md).

## How a Check Runs

1. **Bytes.** The whole string is checked for invalid UTF-8, then for C0 control characters
   other than tab, LF and CR. Either failure is the only error and nothing else runs.
2. **Line endings.** CR and CRLF become LF, as the HTML parser's input step does. Details
   quote the content after this step.
3. **Tokens.** The content is tokenized as HTML5 and every start tag is checked as it is
   produced. Text, comments and end tags are never checked, with three exceptions: the text
   inside `<style>` is checked as CSS; a `<` inside the text of `<style>`, `<iframe>` or
   `<textarea>`, or inside a comment that is not `<!-- -->`, reports `less-than-in-text`; and
   the markup inside a `<!--[if ...]> ... <![endif]-->` conditional comment gets the same rules
   as the content around it. Runs
   of text and of tags whose every attribute a regex
   proves safe (a listed element, double-quoted values with no character reference but
   `&amp;`, no scheme but a listed one, no CSS construct the CSS check reads) are stepped over
   without being tokenized; a run the regex does not match is tokenized and checked as usual,
   so the result is the same with `$fastPath` off.
4. **Per start tag**, in this order: the element must be allowed, or the tag reports
   `element-not-allowed` and its attributes are skipped. An `<iframe>` then has its `src` host
   checked. Then each attribute: an `on*` name reports `event-handler`; a name not on the
   allowlist reports `attribute-not-allowed`; `style` is checked as CSS; every other value has
   its URL scheme checked.
5. **Unclosed markup.** If the content ended inside a tag, a comment, a doctype or a raw-text
   element, `unclosed-markup` is added last. Inside a conditional comment the open piece ends
   at the comment's end, so its `unclosed-markup` comes before the errors after the comment.
6. **Cap.** The check stops after `$maxErrors` distinct errors.

## Rules: Bytes

- **`not-utf8`**: any byte sequence that is not well-formed UTF-8, including overlong forms,
  surrogates (`\xED\xA0\x80`) and code points above U+10FFFF. A UTF-8 byte order mark is a
  valid character and passes.
- **`control-character`**: any of U+0000 to U+001F except tab (U+0009), LF (U+000A) and CR
  (U+000D), anywhere in the content, including inside a tag name or attribute value. Delete
  (U+007F), the C1 range (U+0080 to U+009F) and Unicode line separators pass. A browser would
  turn a NUL inside `<scr\0ipt>` into `<scr�ipt>`, a harmless tag; the rule is stricter and
  refuses the byte wherever it is.

## Rules: Elements

For every start tag, by its lowercased name:

- In `ELEMENTS` (the [elements table](../ai-reference.md#rule-tables)): passes.
- `style`: passes when `$allowStyleElement` is set. Its text content is then checked as CSS.
- `iframe`: passes when `$allowEmbeds` is set, then its `src` is checked, see
  [Iframes](#rules-iframes).
- In `FORM_ELEMENTS`: passes when `$allowForms` is set.
- A Word name (`o:`, `v:` or `w:`, then a letter, then letters and digits: `o:p`, `v:shape`,
  `w:sdtPr`): passes.
- Anything else: `element-not-allowed` with the tag as written. The tag's attributes are not
  checked, so `<script onload="x" src="javascript:1">` is one error.

A `<!DOCTYPE ...>` anywhere reports `element-not-allowed` with the doctype as written.

Not allowed on purpose, so never add them to content to "fix" a rejection:

- Script and script surfaces: `script`, `noscript`, `template`, `svg`, `math`.
- Plugins: `object`, `embed`, `applet`.
- Page-level: `html`, `head`, `body`, `title`, `meta`, `base`, `link`, `frameset`, `frame`.
- Elements whose content is text to a browser and has no use in content: `plaintext`, `xmp`,
  `noembed`, `noframes`.
- Custom elements (`my-widget`, `product-card`): a page script can define one and run its own
  code for every copy in the content.
- Names with a namespace prefix (`t:set`, `svg:rect`, `xsl:template`). The exception is Word's
  `o:`, `v:` and `w:` (`o:p`, `v:shape`, `w:sdt`), which pass with any name after the colon as
  unknown elements a browser does nothing with; their attributes get the normal rules.

End tags are never checked: `</script>` with no start tag is fine. Text is never checked:
`&lt;script&gt;` is text to a browser and to the check. Comments are not checked:
`<!-- <script>alert(1)</script> -->` passes. The one exception is a conditional comment:
the markup inside `<!--[if IE]> ... <![endif]-->` gets the same rules as the rest, see
[Text That Is Not Markup](#rules-text-that-is-not-markup). A `<?import ...>` instruction,
which bound a behavior to a prefix in IE 5.5 to 9, reports `element-not-allowed` as written.

## Rules: Attributes

For every attribute on an allowed element, by its lowercased name, in this order:

1. A name starting with `on` (`onclick`, `oncommand`, `onfoo`, `on`): `event-handler`. No
   allowed attribute starts with `on`, and the prefix covers handlers added in future
   browsers. The name is checked as written: `on&#99;lick` is refused although a browser
   would not decode it either.
2. A name not on the allowlist: `attribute-not-allowed`. A name passes when it is
   - in `ATTRIBUTES`, on any element: the HTML Standard's attribute index minus `srcdoc`,
     `formaction` and `is`, plus `role`, `xml:lang`, `xml:space`, the obsolete presentational
     attributes (`align`, `bgcolor`) and Word's own (`coordsize`, `sdttag`). See
     [Rule Tables](../ai-reference.md#rule-tables)
   - prefixed by `aria-`, `o:`, `v:` or `w:` (`ATTRIBUTE_PREFIXES`)
   - `xmlns:` and any prefix, with a value starting with an entry in `OFFICE_NAMESPACES`. Any
     other value is reported with the name; in a page served as XHTML,
     `<o:script xmlns:o="http://www.w3.org/1999/xhtml">` is a script
   - `formaction` (`FORM_ATTRIBUTES`) while `$allowForms` is set; it is then a URL attribute
3. `style` while `$allowStyleAttribute` is off: `attribute-not-allowed`. With it on, the value is
   checked as CSS, see [CSS](#rules-css).
4. Every other attribute: the value's URL scheme is checked, see [URLs](#rules-urls).

Refused, among others: `data-*` (HTMX `data-hx-get`, Stimulus `data-action`), `srcdoc`, `is`,
framework directives (`x-init`, `@click`, `:href`, `hx-get`, `ng-click`, `_`, `script`),
`xlink:href`, bare `xmlns`, Mailchimp's `mc:edit`, and made-up names. A page's own JavaScript
can run code from these; a browser never does.

Duplicate attributes: the first wins and the rest are dropped, as in browsers. So
`<a href="https://x" href="javascript:y">` passes and the reverse order rejects.

## Rules: URLs

The scheme of a value is read the way a browser reads a URL: ASCII whitespace and control
characters are removed from the whole value first (so `java\nscript:` and `jav&Tab;ascript:`
are `javascript:`), then the value must start with a letter followed by letters, digits,
`+`, `-` or `.` and a colon. Attribute values arrive entity-decoded, so `javascript&colon;`
and `&#106;avascript:` are `javascript:` too. A value with no scheme (a relative path,
`/root`, `//host`, `#id`, `?query`, an empty string, `/time/12:30`) has nothing to check.

- On the attributes in `URL_ATTRIBUTES` (`href`, `src`, `action`, `formaction`, `poster`,
  `ping`, `srcset`, `cite`, `longdesc`, `background`), the scheme must be in `$urlSchemes`:
  `http`, `https`, `mailto`, `tel` by default. Case-insensitive. `data:`, `vbscript:`, `ftp:`,
  `file:` and every other scheme reject with `url-scheme-not-allowed`. One exception:
  `<img src>` also accepts `data:image/...`, any image type, `svg+xml` included, because a
  browser decodes an `img` resource as a picture and nothing else. `data:` on `srcset`,
  `poster` or `href`, in an `<iframe>` or in CSS `url()` still rejects.
- On every other attribute, only the schemes in `SCRIPT_SCHEMES` reject: `javascript`. A
  browser ignores `javascript:` in `title=` or `value=`, but a page script that copies the
  value into a link or into `location` runs it. `vbscript:` and `data:` pass there because
  no current browser runs either as the page; `title="Note: x"`, `aria-label="noon:sharp"` and
  `data="Note: x"` pass because they are not URLs.

The detail is the attribute name and the decoded value: `href="javascript:alert(1)"`.

`srcset` and `ping` hold a list of URLs, split at whitespace and commas, and every URL in
the list is checked: `srcset="a.png 1x, javascript:alert(1) 2x"` rejects, and the detail is
the whole attribute. A comma inside a URL splits it too, so `srcset="x.php?a=1,b:2 1x"`
rejects for a `b:` scheme; a browser reads it as one URL.

## Rules: Iframes

An `<iframe>` passes only when `$allowEmbeds` is set (else `element-not-allowed`) and its
`src`, after whitespace is removed, matches `https://`, `http://` or `//`, then a host in
`$iframeHosts` (case-insensitive, whole host), then the end of the value or `/`, `?` or `#`.
Anything else reports `iframe-host` with the `src` as the detail, or `(no src)` when the
attribute is missing or empty.

So `https://www.youtube.com/embed/x`, `//www.youtube.com/embed/x` and
`https://WWW.YOUTUBE.COM` pass with the default list. `https://www.youtube.com:8080/x`,
`https://www.youtube.com@evil.example/`, `https://evil.www.youtube.com/`,
`https://www.youtube.com.evil.example/`, `/uploads/page.html`, `javascript:x`, `data:text/html,x`
and an `<iframe>` with only `srcdoc` all reject. An empty `$iframeHosts` rejects every
`<iframe>`.

The other attributes on the `<iframe>` are still checked (`onload` rejects, `srcdoc`
rejects). Its content is raw text to a browser, so `<iframe src="..."><script>x</script></iframe>`
holds no script tag.

## Rules: CSS

Applies to every `style` attribute value (entity-decoded) and to the text inside every
`<style>` element (as written, no entity decoding, as in a browser). Comments and quoted
strings are removed first (see below), then three checks run, case-insensitive:

1. Any of these tokens rejects with `css-not-allowed` and the token as the detail: a
   backslash `\`, `-moz-binding`, `behavior:` or `-ms-behavior:` as a property name (whitespace before the
   colon allowed; `scroll-behavior:` and `overscroll-behavior:` pass), an attribute selector using `^=`,
   `$=` or `*=` (`[value^=`, `[ value ^=`) on any name but `style`, `src` and `class`, and
   `unicode-range`. The three names pass with spaces around them and with a namespace
   prefix (`[*|style^=`, `[|src$=`); a longer name that starts or ends with one does not
   (`[styles^=`, `[srcset*=`, `[data-src*=`).
2. Every function name must be in `CSS_FUNCTIONS` and every at-rule name in `CSS_AT_RULES`,
   or it rejects with the name as written as the detail: `image-set(`, `expression(`,
   `-webkit-image-set(`, `cross-fade(`, `@import`, `@layer`. A function name is a whole
   name before `(` (`x-rotate(` is `x-rotate`, not `rotate`), never one right after `@`
   (`@media(min-width:720px)` is the at-rule). An unquoted `url()` is skipped, so the `@` in
   `url(https://cdn.example/logo@2x.png)` and an `(` inside one are part of the URL. The
   lists cover what editors, Word pastes, email templates and Wikipedia use: colors,
   gradients, transforms, filters, shapes, `calc()` and `var()`, selectors such as `:not()`,
   and `@font-face`, `@page`, `@list`, `@media`, `@supports`, `@keyframes`, `@charset`.
   Outside resources load through functions, so a function browsers add later is refused
   until it is listed.
3. Every `url(` is read up to its closing quote, or when unquoted up to `)` or whitespace, and
   its scheme, read as in [URLs](#rules-urls) with tabs and newlines dropped, must be absent or
   in `CSS_URL_SCHEMES`: `http`, `https`. So
   `url(images/bg.png)`, `url(/x.png)`, `url(//cdn.example/x.png)`, `url(#clip)` and
   `url("https://cdn.example/x.png")` pass; `url(javascript:x)`, `url(data:image/svg+xml,...)`,
   `url(vbscript:x)`, `url(mailto:x)`, `url(ftp://x)` and `url("java<tab>script:x")` reject, with the `url(` and its
   target as the detail. This check reads the text as written, comments and strings included, so a quoted
   target is still checked, and each `url(` is read on its own, so in `/*url(*/background:url(javascript:x)`
   the `url(` in the comment cannot take the real `url()` after it as its argument. A backslash inside a quoted `url()` argument also rejects, since
   an escape there still spells a scheme: `url('\6a avascript:x')` reports `url('\`.

The backslash ban is what makes the regexes enough: with no escape syntax there is no way to
spell a function name, an at-rule or `url(` that the regex reads differently from a browser. The two
selectors and `unicode-range` are the CSS features that fire on page data: `[value^="a"]` tests
an input's value one character at a time, and a font with `unicode-range` loads only when a
character is on the page, so either plus a `url()` to another host reports what the page
holds. `style`, `src` and `class` hold layout, image addresses and class names, never a
token, so substring selectors on them pass (`div[style*="margin: 16px 0"]` in email
templates, `img[src*="logo"]`). Exact-match selectors (`[type=text]`, `[href="https://x/"]`), `~=`, `|=`, presence
selectors, property names, `:hover`, `!important`, `position: fixed` and vendor properties
(`mso-*`) are not checked.

Removal reads left to right as a CSS tokenizer does: a comment runs from `/*` to `*/` or
the end of the text, and a string from its quote to the matching quote or a bare newline.
Inside a string `\` escapes the next character, or up to six hex digits and one whitespace
after them, newline included, so `"\a` and a newline do not end the string. A `\` at the
end of the text ends the string, as it does in CSS. CR, CRLF and form feed are first turned
into one newline each, as CSS does, so `style="'&#13;;background:url(\64 ata:,x)"` rejects
on the `\`: the CR from `&#13;` ends the string. An unquoted `url()` runs to its closing `)`
before either is looked for, so a `/*` or a quote inside it is part of the URL, not the start
of a comment or string. So a backslash in a comment banner, `content: '\a0'` and a font name
like `'\@Yu Mincho'` pass, and `content: '/*'` cannot hide what follows it. An unquoted
`url()` is a URL only where `url(` starts a name: after a letter, digit, `_`, `-`, `#`, `@`
or non-ASCII character (`1url(`, `#url(`) it is part of a number, hash or longer name
followed by a `(` block, and a comment inside it is a comment. Only space, tab and newline
can come between `url(` and a quote; after any other character, such as a vertical tab from
`&#11;`, a browser reads a bad URL up to the `)`, and so does the check. A comment is
dropped without leaving a space, as a browser drops it, so `[value/**/^=` still rejects and
reports `[value^=`. A block that removal or any of the three scans cannot finish within a
PCRE limit (megabytes inside one comment or string, or of `x*|` pairs inside one `[`)
rejects with `css-not-allowed` and its first 80 characters as the detail; it is never
passed unchecked.

## Rules: Text That Is Not Markup

The content of `<style>`, `<iframe>` and `<textarea>`, and a comment that is not `<!-- -->`
(`<?php ... ?>`, `<!x ...>`, `</ x>`, `<![CDATA[...]]>`, all ending at the first `>`), is
text to a browser. A `<` inside any of them reports `less-than-in-text`, with the text from
that `<` to the end of the block as the detail. The bytes as written are checked, so `&lt;`
in a `<textarea>` passes, and so does `<?xml:namespace prefix = o />` from a Word paste.

Inside `<style>`, a `<!--` passes when the first `-->` after it is in the same block: Word
wraps its stylesheet in `<style><!-- ... --></style>`. `strip_tags()` reads `<!--` as a
comment that runs to the first `-->`, so a `-->` outside the block would end that comment
somewhere the check saw markup: `<style><!--</style><img alt="--><img src=x onerror=alert(1)>">`
comes out of `strip_tags($html, '<img>')` as a live `<img onerror>`, and reports `<!--`.
Every other `<` in the block is still refused, so
`<style><!-- <img src=x onerror=alert(1)> --></style>` reports the `<img`. The exception is
for `<style>` only; `<textarea><!-- x --></textarea>` reports.

A conditional comment, `<!--[if mso]> ... <![endif]-->`, is a comment to every browser since
IE 10, but the IE engine inside old Windows programs and Outlook's Word engine read the
markup inside it. That markup gets the same rules as the content around it, from the first
`]>` to `<![endif]`, or to the end of the comment when there is none: an email template's
`<!--[if mso]><table>...</table><![endif]-->` passes, and
`<!--[if IE]><script>alert(1)</script><![endif]-->` reports `element-not-allowed` with
`<script>`. A `<!--[if` inside one never closes, since the outer comment ends at the first
`-->`, so it reports `unclosed-markup` once and the markup inside it is not read again.
Ordinary text and everything else inside `<!-- -->` are never checked.

The reason is what happens after the check: `strip_tags()` with an allow list and HTML4-era
DOM parsers read a tag inside these as live. Content that passed the check stays safe
through them only if no tag can appear where the check saw text.

## Rules: Unclosed Markup

If the content ends inside a start tag, an end tag, a quoted attribute value, a comment
(`<!-- x`, `<!-- x --`), a bogus comment (`<?php x`), a doctype, or the text of a raw-text
element (`<style>`, `<iframe>`, `<textarea>`, `<plaintext>`) with no matching end tag,
`unclosed-markup` is reported with the unfinished markup as the detail, from where it
opened to the end of the content. It is always the last error.

A browser drops an unfinished tag at the end of a document. A fragment printed into a page
is not at the end: the page up to the next quote becomes the attribute value (the URL that
gets fetched, for `src`), an `onerror=` on the open tag runs once the page's next `>` closes
it, and the page up to `-->` or `</style>` disappears or becomes CSS.

Not unclosed: a lone `<` or `</` at the end (text), `<p>` with no `</p>` (a tag, closed with
its `>`), `3 > 2` in text, a self-closing `<img/>`, an empty string.

## What the Tokenizer Sees

The check runs on the HTML5 token stream, so the facts below are what a browser does too.
Each one is a test in the suite, and the html5lib tokenizer test suite runs against the
tokenizer with element state switching off.

- Tag and attribute names are ASCII-lowercased and never entity-decoded: `<ScRiPt>` is
  `script`; `on&#99;lick` is an attribute named `on&#99;lick`.
- Attribute values are entity-decoded with the HTML5 table (all named references, legacy
  names without a semicolon, numeric references, `&#x80;` to `&#x9F;` through windows-1252),
  so the URL and CSS checks see what the browser sees. Text and `<style>` content are not
  relevant to decoding for the rules except as noted under CSS.
- `/` inside a tag is a separator: `<p/onclick=x>` is a `p` with `onclick`.
- Unquoted values end at whitespace or `>`: `<img src=x onerror=alert(1)>` has two attributes.
- `<a<b href=x>` is a tag named `a<b`, rejected as unknown.
- `<!-- x --!>` closes a comment; `<!-->` and `<!--->` are empty comments; `<?php ... ?>` and
  `<![CDATA[...]]>` are comments ending at the first `>`; `</ x>` and `</3>` are comments;
  `</>` is dropped.
- `<style>`, `<iframe>`, `<noembed>`, `<noframes>`, `<xmp>`, `<noscript>` and `<script>` hold
  raw text up to their matching end tag (case-insensitive, the same name); `<title>` and
  `<textarea>` the same but entity-decoded. A `<img onerror>` inside `<style>` is text, and
  its `<` is refused (see [Rules: Text That Is Not Markup](#rules-text-that-is-not-markup)).
  `<noscript>` is raw text because scripting is on in every browser a person uses.
- `<svg>` and `<math>` content would be tokenized as HTML, not as foreign content; both
  elements are refused so this never matters.
- A NUL in a tag or attribute name becomes U+FFFD in a browser; here the byte check refuses
  it first.
- Nesting depth is irrelevant: there is no tree, so there is no depth.

