# HtmlSecurityCheck Changelog

> Tagged releases roll up every change since the previous tag. Versions bundled
> with CMS Builder are marked on their sections. Error codes are never renamed
> once released; message wording can change, so match on the code.

## [UNRELEASED]

First release. `HtmlSecurityCheck::check()` runs the HTML5 tokenizer over rich-text content and
rejects anything that could run script in the page it is printed into, with a `Result` of
`Violation`s that quote the tag or attribute at fault. The content is never rewritten.

### Added

- **`HtmlSecurityCheck::check()`** returns a `Result` with `ok` and `errors`, one `Violation`
  per distinct problem, in document order, capped at 50. Nothing throws.
- **Element allowlist**: the HTML Standard's element index plus the obsolete presentational
  elements, Word's `o:`, `v:` and `w:` prefixed names and its `<xml>` settings block. Script, plugin, head-level and
  tokenizer-switching elements are refused with no switch.
- **Attribute allowlist**: the HTML Standard's attribute index minus `srcdoc` and `is`, plus the
  obsolete presentational attributes, `aria-*` and Word's own; `on*` and unknown names are
  refused. `href`, `src` and the other URL attributes limited to `http:`, `https:`, `mailto:`,
  `tel:` or no scheme, plus `data:image/...` on `<img src>`; `javascript:` refused at the
  start of every other attribute value but `style`, which is checked as CSS.
- **CSS check** on the `style` attribute and the `<style>` element: function names and
  at-rules must be on allowlists (`image-set(`, `expression(` and `@import` are not), the
  backslash and the properties that ran script in old IE and Firefox are refused, `url()`
  is limited to `http:`, `https:` or relative, and the two
  selectors that leak page data (`[attr^=]` and friends on any attribute but `style`, `src`
  and `class`, and `unicode-range`).
- **Switches** as static properties: `$allowForms` (off), `$allowStyleAttribute` (on),
  `$allowStyleElement` (on), `$allowEmbeds` (on) with `$iframeHosts` for the `<iframe>` hosts that pass, and
  `$urlSchemes` for what a URL attribute may start with (`javascript` stays refused
  whatever it holds).
- **Unclosed markup** (`<img src="`, `<!-- x`, `<style>` with no end tag) is refused, since a
  fragment printed into a page keeps reading the page as part of it.
- **A `<` inside raw text is refused** (`less-than-in-text`): the content of `<style>`, `<iframe>`
  and `<textarea>`, and comments that are not `<!-- -->`, are text to a browser but tags to
  `strip_tags()` with an allow list or an HTML4-era parser, so a fragment that passed the check
  stays safe through them. A `<!--` inside `<style>` passes when its `-->` is in
  the same block, for Word's `<style><!-- ... --></style>`.
- **Old IE's markup**: the markup inside a `<!--[if ...]>` conditional comment gets the same
  rules as the rest, for the IE engine inside old Windows programs and Outlook's Word engine,
  so email templates' `<!--[if mso]>` tables pass and a script inside one rejects.
  `<?import ...>`, which bound a behavior to a prefix in IE 5.5 to 9, is refused.
- **Byte checks**: invalid UTF-8 and C0 control characters are refused before tokenizing.
- **A regex that stops at a PCRE limit refuses the content**, never reads as "no match":
  `not-checked` names the limit, and CSS reports the block as `css-not-allowed`.
- **`HtmlSecurityCheck::rules()`** returns the rule tables and current switch values for
  settings pages and docs. `Violation::TEMPLATES` holds every message for translation.
- **A fast path**: text and tags the rules can never refuse (a listed element, double-quoted
  attributes with nothing a rule looks at) are stepped over in one regex match instead of one
  token at a time. `HtmlSecurityCheck::$fastPath = false` sends every byte through the tokenizer:
  the same result, slower.
- **An HTML5 tokenizer** written from the HTML Standard's state list, run against the
  html5lib tokenizer test suite in CI. Internal: the public API is `HtmlSecurityCheck`, `Result`
  and `Violation`.
- **A browser double-check** in CI: everything the check accepts (the fixtures, the html5lib
  inputs and the corpus) is parsed by headless Chrome, and the tree it builds must hold no
  script element, no `on*` attribute and no `javascript:` URL.
