# Internal Docs

Docs for people working on HtmlSecurityCheck itself, not for people using it in their apps.
Library users want the [README](../../README.md) instead.

- [rules.md](rules.md) - Every rule the check enforces, in the order it runs them; the specification the security scans read.
- [design-decisions.md](design-decisions.md) - The threat model, why the rules and the API are shaped the way they are, and the known non-goals.
- [style.md](style.md) - HtmlSecurityCheck-specific writing conventions on top of the shared docs standards.

## After Changing a Rule

The fast path, the generated rule tables and the docs don't fail on their own when a rule
changes, so check each of these in the same commit:

- **Fast path.** A rule that refuses something new needs the same refusal in
  `knownSafePattern()`, or the fast path steps over it; add the case to `FastPathTest`'s
  near-miss list. A rule that passes something new needs no pattern change, but an element
  that switches the tokenizer's state must stay out of `ELEMENTS`.
- **Corpus.** `php -d xdebug.mode=off tools/tally.php` should report no surprises. A surprise
  that is expected goes in `tools/corpus-known.json` under the hash the tally prints.
- **Rule tables.** `php tools/rules-doc.php` rewrites the tables in `docs/ai-reference.md`
  after a change to a rule constant (`RulesDocTest` fails until it runs).
- **Regexes.** Every regex over content is possessive or refuses the content when it stops at
  a PCRE limit, and CI runs the suite again with `pcre.jit=0`. A loop that calls `strpos()`
  from each match can be quadratic: test a megabyte of the shape it repeats on.
- **Imports.** A built-in function new to a file goes in its `use function` list
  (`NamespacedCallsTest`).
- **Docs.** `docs/internal/rules.md` (the exact rule), `docs/ai-reference.md` (the summary),
  `docs/errors.md` (when an error's text or fix changes), and the README when what it rejects
  changes.
- **Tests.** `vendor/bin/phpunit`, which includes `ChromeCrossCheckTest`: headless Chrome
  parses everything the check accepts, plus a fixed-seed CSS fuzz.
- **CSS.** After a CSS rule change, run `php -d xdebug.mode=off tools/css-fuzz.php` (10
  minutes; `--minutes=300` before a release). A fragment it flags gets a test and goes in
  `CssFuzzer::SEEDS`.
- **URLs.** After a URL or iframe rule change, run `php -d xdebug.mode=off tools/url-fuzz.php
  --log-dir=<dir>` (10 minutes; `--workers=8` for more seeds at once). A fragment it flags
  gets a test and goes in `UrlFuzzer::SEEDS`.
