<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

/**
 * Random CSS for the Chrome cross-check: known ways to hide CSS from a scanner, each edited one to
 * four times at random, next to a marker the check refuses on its own. When the check accepts a
 * fragment and Chrome still finds the marker in the CSS it parsed, the check read that CSS
 * differently from a browser.
 *
 * In a style attribute the edits can also insert ASCII controls, and the CSS is written with random
 * character references, since a reference is how CR, FF and NUL reach the CSS of an attribute.
 *
 *     $fragments = CssFuzzer::fragments(seed: 1, samples: 50000);   // [id => html], the same for the same seed
 *
 * Markers that load something request /<marker>/<id>, relative to the page, so when
 * tools/css-fuzz.php serves the page through its request logger, the log names the fragments
 * whose CSS reached the network.
 *
 * A fragment that once got through goes in SEEDS, so every run after that tries it and its
 * near misses again.
 */
final class CssFuzzer
{
    // CSS the check refuses in a <style> block, one per way it could leak page data or load something
    private const STYLE_MARKERS = [
        'prefix'   => '[title^=a]{color:red}',
        'suffix'   => '[title$=c]{background:url(/suffix/__ID__)}',
        'contains' => '[title*=b]{background:url(/contains/__ID__)}',
        'import'   => '@import url(/import/__ID__);',
        'font'     => '@font-face{font-family:f__ID__;src:url(/font/__ID__);unicode-range:U+61}p{font-family:f__ID__}',
        'function' => 'p{background:image-set(url(/function/__ID__) 1x)}',
        'scheme'   => 'p{background:url(javascript:__ID__)}',
    ];

    // the same for a style attribute, which has no selectors or at-rules
    private const ATTRIBUTE_MARKERS = [
        'function' => 'background:image-set(url(/function/__ID__) 1x)',
        'scheme'   => 'background:url(javascript:__ID__)',
        'escfn'    => 'background:\\69mage-set(url(/escfn/__ID__) 1x)',
        'escurl'   => 'background:u\\72l("data:__ID__")',
    ];

    // CSS that hid what came after it from the check once, or that the tests use for the same idea
    private const SEEDS = [
        "a{a:1url(/*)'*/)}", "a{--x:#url(/*)'*/)}", "a{b:url(/*)}", "a{b:url(/*)}/* */", "x{background:url(x\");}",
        "a{b:url(x')}", "p{--x:\"\\a\n\"}", "p{--x:'\\201c\n'}", "a{x:'\\'}", "a{x:\"\\\"\"}", "a{content:'/*'}",
        "a{b:url(\"x\") /* x */}", '/* x */', '/**/', '<!-- -->', '<!--', '@media x{', '@media x{}', 'a{}', 'a{b:c}',
        'a{b:url( x )}', "a{b:url(x\\))}", '@import url(x);', 'a{b:c(/*)*/)}', "a{b:\"x\ny\"}",
        'a{--x:u/**/rl(x)}', 'a{--x:---f(x)}', "a{--x:url('a';c(x)}",
    ];

    // what an edit inserts: CSS tokens and the pieces of them that tricks are built from
    private const PIECES = [
        '/*', '*/', '/**/', "'", '"', '\\', "\n", ' ', 'x', '1', '#', '@', '-', '--x:',
        'url(', '1url(', '#url(', "\u{e9}url(", '-url(', 'URL(', '@url(', 'url( ', 'url("', "url('",
        '(', ')', '{', '}', ';', ':', ',', '[', ']', '=', '/', '*',
        'a{b:', '@media x{', '@supports (x:y){', '<!--', '-->',
        '\\a ', '\\a', "\\'", '\\"', '\\)', '\\(', '\\*', '\\/', '\\2a ', '\\\n', '\\75 rl(',
    ];

    // more of the same for a style attribute, whose CSS can hold CR, FF and other controls written as character references
    private const ATTRIBUTE_SEEDS = [
        '--x:"a"', "--x:'a'", 'a:"\\61 "', 'color:red', '--x:url(a)', 'a:b;c:d', "--x:\"\\\r\n\"", '--x:/* */',
    ];
    private const ATTRIBUTE_PIECES = [
        "\r", "\f", "\r\n", "\x0B", "\x01", "\x7F", "\0", "\\\r\n", "\\61\r\n", "\\61\f", '";', "';",
    ];

    // named references for the characters CSS reads, each with the spellings HTML decodes
    private const NAMED_REFERENCES = [
        '"'  => ['&quot;', '&quot', '&QUOT;', '&QUOT'], "'" => ['&apos;'], '&' => ['&amp;', '&amp', '&AMP;'],
        '<'  => ['&lt;', '&lt', '&LT;'], '>' => ['&gt;', '&gt'], '(' => ['&lpar;'], ')' => ['&rpar;'], '/' => ['&sol;'],
        '*'  => ['&ast;', '&midast;'], '\\' => ['&bsol;'], ':' => ['&colon;'], ';' => ['&semi;'], '{' => ['&lbrace;', '&lcub;'],
        '}'  => ['&rbrace;', '&rcub;'], '[' => ['&lsqb;', '&lbrack;'], ']' => ['&rsqb;'], '@' => ['&commat;'], '=' => ['&equals;'],
        "\n" => ['&NewLine;'], "\t" => ['&Tab;'], '#' => ['&num;'], '.' => ['&period;'], ',' => ['&comma;'], '!' => ['&excl;'],
        '$'  => ['&dollar;'], '^' => ['&Hat;'], '_' => ['&lowbar;', '&UnderBar;'],
    ];

    /**
     * Distinct fragments from the seed, keyed by an id that is also in each marker's URL. Each is a
     * <style> block, or one time in four a <p> with a style attribute
     *
     * @return array<int, string>
     */
    public static function fragments(int $seed, int $samples): array
    {
        // single characters an edit inserts: printable ASCII, tab, newline, form feed and a few non-ASCII letters,
        // and in a style attribute every ASCII control too
        $nonAscii       = ["\u{e9}", "\u{a0}", "\u{2028}", "\u{feff}", "\u{85}"];
        $styleEdits     = [self::SEEDS, self::PIECES, [...array_map(chr(...), range(0x20, 0x7E)), "\t", "\n", "\f", ...$nonAscii]];
        $attributeEdits = [[...self::SEEDS, ...self::ATTRIBUTE_SEEDS], [...self::PIECES, ...self::ATTRIBUTE_PIECES], [...array_map(chr(...), range(0x00, 0x7F)), ...$nonAscii]];

        mt_srand($seed);
        $distinct = [];
        for ($i = 0; $i < $samples; $i++) {
            $inAttribute                   = mt_rand(0, 3) === 0;
            [$seeds, $pieces, $characters] = $inAttribute ? $attributeEdits : $styleEdits;
            $css                           = self::pick($seeds);
            for ($edits = mt_rand(1, 4); $edits > 0; $edits--) {
                $at     = mt_rand(0, strlen($css));
                $insert = mt_rand(0, 1) ? self::pick($characters) : self::pick($pieces);
                $css    = match (mt_rand(0, 2)) {
                    0 => substr($css, 0, $at) . $insert . substr($css, $at),
                    1 => substr($css, 0, $at) . substr($css, $at + 1),
                    2 => substr($css, 0, $at) . $insert . substr($css, $at + 1),
                };
            }
            if (!mb_check_encoding($css, 'UTF-8')) {
                continue;
            }
            $marker = self::pick($inAttribute ? self::ATTRIBUTE_MARKERS : self::STYLE_MARKERS);
            $glue   = $inAttribute ? ';' : '';   // a declaration needs a ; to stay apart from the CSS next to it
            $css    = mt_rand(0, 3) === 0 ? $marker . $glue . $css : $css . $glue . $marker;   // marker first sometimes: Chrome keeps @import only at the start
            $distinct[($inAttribute ? 'attribute ' : 'style ') . $css] ??= [$inAttribute, $css];
        }

        // the attribute's character references come after the id is in, so the id in the marker URLs stays as written
        $fragments = [];
        foreach (array_values($distinct) as $id => [$inAttribute, $css]) {
            $css         = str_replace('__ID__', (string)$id, $css);
            $fragments[] = $inAttribute ? '<p title="abc" style=' . self::attributeValue($css) . '>abc</p>' : "<style>$css</style>";
        }
        mt_srand();   // back to a random sequence for whatever runs next
        return $fragments;
    }

    /**
     * The CSS as an attribute value, double quoted most of the time, single quoted or unquoted
     * sometimes, with any character as a character reference one time in five. Characters that
     * would end the value, and controls raw HTML can't carry (CR, FF, NUL), are always references.
     *
     *     self::attributeValue("a:'\r'");   // e.g. "a:&#39;&#x0D;'" or 'a:&apos;&#13&#39;'
     */
    private static function attributeValue(string $css): string
    {
        $quote      = self::pick(['"', '"', "'", '']);
        $characters = mb_str_split($css);
        $value      = '';
        foreach ($characters as $k => $char) {
            $endsValue       = $quote === '' ? str_contains("\t\n\f\r \"'=`>", $char) : $char === $quote;   // unquoted, a quote first would start a quoted value
            $mustBeReference = $endsValue || $char === '&' || preg_match('/^[\x00-\x08\x0B-\x1F\x7F]$/', $char);   // ASCII controls but tab and LF
            if (!$mustBeReference && mt_rand(0, 4)) {
                $value .= $char;
                continue;
            }
            $reference = self::reference($char);
            if (!str_ends_with($reference, ';') && preg_match('/^[0-9A-Za-z;=]/', $characters[$k + 1] ?? '')) {
                $reference .= ';';   // with no semicolon, the reference would read the next character too, or not be decoded
            }
            $value .= $reference;
        }
        return $quote . $value . $quote;
    }

    /**
     * One character as a character reference in a random form: named, decimal or hex, zero padded
     * sometimes, without its semicolon sometimes
     */
    private static function reference(string $char): string
    {
        $form = mt_rand(0, 6);
        if ($form === 0 && isset(self::NAMED_REFERENCES[$char])) {
            return self::pick(self::NAMED_REFERENCES[$char]);
        }
        $codepoint = mb_ord($char);
        $zeros     = mt_rand(0, 3) ? '' : str_repeat('0', mt_rand(1, 8));
        $semicolon = mt_rand(0, 4) ? ';' : '';
        return match ($form % 3) {
            0, 1 => "&#$zeros$codepoint$semicolon",
            2    => '&#' . self::pick(['x', 'X']) . $zeros . self::pick([dechex($codepoint), strtoupper(dechex($codepoint))]) . $semicolon,
        };
    }

    /**
     * A random value from the list, from mt_rand, whose seeded sequence PHP keeps the same across versions
     */
    private static function pick(array $list): mixed
    {
        return $list[array_keys($list)[mt_rand(0, count($list) - 1)]];
    }
}
