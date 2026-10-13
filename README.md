<!--
AI ASSISTANTS: Before writing code that uses HtmlSecurityCheck, read docs/ai-reference.md. It covers
the complete API and every rejection rule in one file and matches this installed version.
HtmlSecurityCheck rejects content instead of cleaning it, which differs from the HTML sanitizers in
training data, so trust that file over habit.
-->

# HtmlSecurityCheck: Reject HTML That Could Run Script

HtmlSecurityCheck checks the HTML a WYSIWYG editor such as TinyMCE or CKEditor submits, and
rejects it if it could run script, send the page's data to another host, or mislead the
visitor. Each error names the tag or attribute that failed.

- **It never rewrites the content.** What passes is stored and shown byte for byte as submitted.
- **It reads HTML the way a browser does.** Its HTML5 tokenizer is tested against html5lib's
  tests, and CI fails if headless Chrome finds script in anything the check accepts.
- **A typical page checks in under 0.04 ms,** 50 to 115 times less than HTMLPurifier takes
  on the same pages ([benchmarks](benchmarks/results.md)).

## Documentation

Full docs ([browse on GitHub](https://github.com/interactivetools-com/HtmlSecurityCheck)):

- [Error codes and fixes](docs/errors.md) - every message, and what to change in the content
- [AI reference](docs/ai-reference.md) - the complete API and every rule in one file, written for AI coding assistants
- [Changelog](CHANGELOG.md)

## Quick Start

Requires PHP 8.1+. No extensions, no dependencies.

```bash
composer require itools/htmlsecuritycheck
```

```php
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;

// save.php: check the field, then store it as submitted
$result = HtmlSecurityCheck::check($_POST['body']);   // never throws; invalid UTF-8 is a rejection too
if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";   // onclick= event handler attributes are not allowed
    }
    exit;
}

// show.php: HTML from an import, an email or an older save was never checked, so check it before printing
echo HtmlSecurityCheck::check($body)->ok ? $body : '<p>This content is hidden until its HTML is fixed.</p>';
```

The rest of the API. The switches are static properties; set them once at startup:

```php
$violation->code;              // 'event-handler', stable across releases, so switch on it
$violation->detail;            // 'onclick', text from the content, so encode it before output
$violation->template;          // '%s= event handler attributes are not allowed', for translation with Violation::TEMPLATES
HtmlSecurityCheck::rules();    // the rule tables and the current switches, for settings pages and debugging

HtmlSecurityCheck::$allowForms          = false;   // <form>, <input>, <button>: a form can imitate a login box
HtmlSecurityCheck::$allowStyleAttribute = true;    // style="...", through the CSS check; styles its own element only
HtmlSecurityCheck::$allowStyleElement   = true;    // <style>, through the CSS check; can restyle the whole page
HtmlSecurityCheck::$allowEmbeds         = true;    // <iframe> whose src host is in $iframeHosts
HtmlSecurityCheck::$iframeHosts         = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];
HtmlSecurityCheck::$urlSchemes          = ['http', 'https', 'mailto', 'tel'];   // add 'sms' or an app's scheme to let links open it
```

## What It Rejects

- **Script:** `<script>`, `onerror=`, `javascript:` and `data:text/html` links, `<object>`,
  `<base>`, and `x-init=`, `data-*` and custom elements, which the page's own JavaScript
  (Alpine, Stimulus, web components) would run.
- **Sending page data to another host:** `<link rel="stylesheet">`, CSS selectors such as
  `input[value^="a"]` with a `url()`, and a tag left open at the end, which turns the page text
  up to the next quote into part of its URL.
- **Misleading the visitor:** `<meta http-equiv="refresh">`, `<form>`, an `<iframe>` from a
  host not in `$iframeHosts`, and links with `search-ms:` or another scheme that opens a program.
- **Markup that browsers and other parsers read differently:** `<svg>`, `<template>`,
  `<noscript>`, a `<` inside `<style>`, and bytes that are not UTF-8.
- **A whole pasted page:** `<html>`, `<head>`, `<body>` and `<title>`.

Element names, attribute names, iframe hosts and the schemes on `href`, `src` and the other URL
attributes must be on the check's lists, so anything it doesn't know is rejected. CSS works the
other way: it rejects a list of patterns (`expression(`, `@import`, `\` escapes, `[value^=`
and a few more), allows `url()` only to `http:`, `https:` or a relative path, and passes the rest.

## Recommended Extra Steps

- **Show rejected content without running it.** Print it in
  `<iframe sandbox srcdoc="<?= htmlspecialchars($body) ?>"></iframe>`: no script, forms or
  popups, and no access to your page or its cookies. Images and CSS still load.
- **Turn script off in the editor.** Content saved before the check existed opens there
  unchecked. Give the editor's frame the policy `script-src 'none'; object-src 'none'`
  (TinyMCE's `content_security_policy` option; iframe mode only).

## What It Does Not Check

- **Where you print it.** A passing fragment is safe between ordinary tags such as `<div>` or
  `<td>`, not inside `<script>`, `<style>`, `<textarea>`, `<title>`, inline SVG or an attribute
  value.
- **End tags.** A `</div>` in the content passes even when it closes one of your page's
  elements, so the content can end a wrapper early.
- **Anything that runs no script.** Images and `url()` on other hosts, `position: fixed`
  overlays, invalid HTML, `id` or `name` values that match a page script's variables, and
  length all pass. Cap the size before the check; check time grows in step with size.

## When You Might Not Want HtmlSecurityCheck

- **Visitor posts.** A rejection works for staff who can fix the HTML, not for a visitor who
  can't. Forum posts and comments need a sanitizer such as [HTMLPurifier](https://htmlpurifier.org/).
- **Inline SVG or MathML.** Both are rejected with no switch. Upload SVG as a file, check it with
  SvgSecurityCheck, and show it with `<img src>`.
- **Markup for Alpine, HTMX, Stimulus or web components.** `data-*` attributes and custom
  elements are rejected with no switch.

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
