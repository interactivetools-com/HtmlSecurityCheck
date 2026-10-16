<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

/**
 * Every character in every spot where the check's CSS scans and a browser could read it
 * differently, for the Chrome cross-check. Each template hides a marker the check refuses on its
 * own, inside a string, a comment, a url() or a name. A character that ends one of those early
 * for one reader and not the other uncovers the marker for that reader only. The cases are fixed,
 * not random, so the same code always gets the same result.
 *
 *     $fragments = CssCharacterGrid::fragments();   // [name => html], about 140,000
 *
 * Two sets. The position templates take each of about 150 code points at every byte position.
 * The spot templates take every code point from 0 to 255 and a dozen Unicode spaces at one named
 * spot, raw and as each form of character reference. In a style attribute a character reference
 * is how CR, FF, VT and NUL reach the CSS.
 */
final class CssCharacterGrid
{
    // style attribute CSS with a refused function or scheme in it; a code point goes in at every position as &#N;
    private const ATTRIBUTE_TEMPLATES = [
        '--x:"a\\61 b";background:image-set(url(/a) 1x)',
        "--x:'a';background:image-set(url(/a) 1x)",
        '--x:"a\\61 b";background:image-set(url(/a) 1x);--y:"',   // a quote after the marker, so a string that ends early for one reader pairs with it
        "--x:'a';background:image-set(url(/a) 1x);--y:'",
        '--x:"ab;background:image-set(url(/a) 1x);--y:"',         // the marker inside a string or comment for both readers
        "--x:'ab;background:image-set(url(/a) 1x);--y:'",
        '--x:/*ab;background:image-set(url(/a) 1x);--y:*/',
        '--x:/*a*/b;background:image-set(url(/a) 1x)',
        '--x:url(a);background:image-set(url(/a) 1x)',
        'background:url(java)',                                   // a code point in the scheme
        'behavior:url(/a)',
        'background:image(url(/a))',
    ];

    // the same for a <style> block, where the code point goes in raw
    private const STYLE_TEMPLATES = [
        'a{--x:"a\\61 b"}[title^=a]{color:red}',
        'a{--x:"a\\61 b"}[title^=a]{color:red}a{--y:"}',
        "a{--x:'a'}@import url(/a);",
        'a{--x:/*a*/b}[title*=b]{color:red}',
        'a{--x:url(a)}p{background:image-set(url(/a) 1x)}',
        '[tit le^=a]{color:red}',
        '@imp ort url(/a);',
        'a{--x:"ab}[title^=a]{color:red}a{--y:"}',
        "a{--x:'ab}[title^=a]{color:red}a{--y:'}",
        'a{--x:/*ab}[title^=a]{color:red}a{--y:*/}',
    ];

    // § is the spot and M the marker, each placed where the check's character classes (\s, \w, the url( lookbehind)
    // and CSS's own (whitespace, newline, name character) could put a character in different classes
    private const SPOT_TEMPLATES = [
        'string-end'      => "--x:'a§;M;'",
        'string-end-dq'   => "--x:\"a§;M;\"",
        'escape-next'     => "--x:'\\§';M;'",
        'hex-escape-ws'   => "--x:'\\3§B';M;'",
        'url-leading-ws'  => "b:url(§'x);M;--z:'",
        'url-inside'      => "b:url(x§'y);M;--z:'",
        'url-trailing'    => "b:url(x§/*);M;--z:*/)",
        'before-url'      => "b:§url(/*);M;--z:*/)",
        'name-continue'   => "b:u§rl(/*);M;--z:*/)",
        'number-then-url' => "b:1§url(/*);M;--z:*/)",
        'comment-split'   => "b:/§*';M;--z:'*/",
        'comment-end'     => "/*a*§/M;/**/",
        'fn-paren'        => "b:url§('x);M;--z:'",
        'string-open'     => "b:§'x;M;'",
    ];
    private const SPOT_STYLE_MARKER     = '}[title^=a]{color:red}a{';
    private const SPOT_ATTRIBUTE_MARKER = 'background:url(\\6a avascript:x)';   // the escape alone is refused, so it passes only when hidden

    /**
     * Every case from both sets, keyed by a name that says which template, spot or position, and
     * which character. A name never holds ": ", since ChromeCrossCheck reads a finding's case name
     * up to the first one
     *
     * @return array<string, string>
     */
    public static function fragments(): array
    {
        return [...self::positionCases(), ...self::spotCases()];
    }

    /**
     * Each code point at every byte position of each position template. A surrogate or a code point
     * past U+10FFFF is reachable only as a reference, so it goes into the attribute templates only.
     *
     * @return array<string, string>
     */
    private static function positionCases(): array
    {
        $codepoints = [...range(0x00, 0x7F), 0x80, 0x85, 0x9F, 0xA0, 0xAD, 0x130, 0x17F, 0x200B, 0x2028, 0x2029, 0x212A, 0xFEFF, 0xFF08, 0xFFFD, 0xD800, 0x110000];
        $sequences  = ['13,10', '92,13', '92,12', '92,13,10'];   // CRLF, and a backslash before CR, FF or CRLF

        $cases = [];
        foreach (self::ATTRIBUTE_TEMPLATES as $t => $template) {
            for ($at = 0; $at <= strlen($template); $at++) {
                foreach ([...$codepoints, ...$sequences] as $cp) {
                    $references = implode('', array_map(fn($n) => "&#$n;", explode(',', (string)$cp)));
                    $css        = htmlspecialchars(substr($template, 0, $at)) . $references . htmlspecialchars(substr($template, $at));
                    $cases["attribute $t at $at $references"] = '<p title="abc" style="' . $css . '">abc</p>';
                }
            }
        }
        foreach (self::STYLE_TEMPLATES as $t => $template) {
            for ($at = 0; $at <= strlen($template); $at++) {
                foreach ($codepoints as $cp) {
                    $char = mb_chr($cp, 'UTF-8');
                    if ($char === false) {
                        continue;
                    }
                    $cases["style $t at $at U+" . sprintf('%04X', $cp)] = '<style>' . substr($template, 0, $at) . $char . substr($template, $at) . '</style>';
                }
            }
        }
        return $cases;
    }

    /**
     * Each code point at the spot in each spot template: raw in a <style> block, and in a style
     * attribute raw and as &#N;, &#xN; and &#N with no semicolon
     *
     * @return array<string, string>
     */
    private static function spotCases(): array
    {
        $codepoints = [...range(0, 255), 0x2028, 0x2029, 0xFEFF, 0xFFFD, 0x3000, 0x200B, 0x180E, 0x1680, 0x2000, 0x205F, 0xD7FF, 0x10FFFF];

        $cases = [];
        foreach (self::SPOT_TEMPLATES as $spot => $template) {
            $attribute = htmlspecialchars(str_replace('M', self::SPOT_ATTRIBUTE_MARKER, $template), ENT_QUOTES);
            foreach ($codepoints as $cp) {
                $char = mb_chr($cp, 'UTF-8');
                $name = "$spot U+" . sprintf('%04X', $cp);

                $cases["$name in style"]          = '<style>a{' . str_replace(['§', 'M'], [$char, self::SPOT_STYLE_MARKER], $template) . '}</style>';
                $cases["$name in attribute"]      = '<p title="abc" style="' . str_replace('§', htmlspecialchars($char, ENT_QUOTES), $attribute) . '">abc</p>';
                foreach (["&#$cp;", '&#x' . dechex($cp) . ';', "&#$cp"] as $reference) {
                    $cases["$name as $reference"] = '<p title="abc" style="' . str_replace('§', $reference, $attribute) . '">abc</p>';
                }
            }
        }
        return $cases;
    }
}
