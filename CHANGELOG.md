# HtmlSecurityCheck Changelog

> Tagged releases roll up every change since the previous tag. Versions bundled
> with CMS Builder are marked on their sections. Error codes are never renamed
> once released; message wording can change, so match on the code.

## [1.0.0] - 2026-10-16

> **Bundled with CMS Builder v3.85**

First release. HtmlSecurityCheck checks rich-text HTML and rejects it if it could run script
in the page it is printed into. It never rewrites the content. The [README](README.md) says
what it blocks and what it does not check.

### Added

- **`HtmlSecurityCheck::check($html)` and `rules()`.** Nothing throws; `rules()` returns the
  rule tables and current switch values for settings pages.
- **`Result` with `ok` and `errors`, `Violation` with `code`, `detail`, `template` and
  `message`.** One error per distinct problem, at most 50. `Violation::TEMPLATES` holds
  every message template for translation.
- **One error code per rule**, listed with its fix in [docs/errors.md](docs/errors.md).
  Codes are stable from this release on.
- **Switches as public static properties** (`$allowForms`, `$allowStyleElement`,
  `$iframeHosts`, `$urlSchemes` and the others in
  [docs/ai-reference.md](docs/ai-reference.md#switches)).

### Requirements

- PHP 8.1+. No other dependencies.
