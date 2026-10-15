<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

/**
 * Random CSS for the Chrome cross-check: known ways to hide CSS from a scanner, each edited one to
 * four times at random, next to a marker the check refuses on its own. When the check accepts a
 * fragment and Chrome still finds the marker in the CSS it parsed, the check read that CSS
 * differently from a browser.
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
    ];

    // CSS that hid what came after it from the check once, or that the tests use for the same idea
    private const SEEDS = [
        "a{a:1url(/*)'*/)}", "a{--x:#url(/*)'*/)}", "a{b:url(/*)}", "a{b:url(/*)}/* */", "x{background:url(x\");}",
        "a{b:url(x')}", "p{--x:\"\\a\n\"}", "p{--x:'\\201c\n'}", "a{x:'\\'}", "a{x:\"\\\"\"}", "a{content:'/*'}",
        "a{b:url(\"x\") /* x */}", '/* x */', '/**/', '<!-- -->', '<!--', '@media x{', '@media x{}', 'a{}', 'a{b:c}',
        'a{b:url( x )}', "a{b:url(x\\))}", '@import url(x);', 'a{b:c(/*)*/)}', "a{b:\"x\ny\"}",
    ];

    // what an edit inserts: CSS tokens and the pieces of them that tricks are built from
    private const PIECES = [
        '/*', '*/', '/**/', "'", '"', '\\', "\n", ' ', 'x', '1', '#', '@', '-', '--x:',
        'url(', '1url(', '#url(', "\u{e9}url(", '-url(', 'URL(', '@url(', 'url( ', 'url("', "url('",
        '(', ')', '{', '}', ';', ':', ',', '[', ']', '=', '/', '*',
        'a{b:', '@media x{', '@supports (x:y){', '<!--', '-->',
        '\\a ', '\\a', "\\'", '\\"', '\\)', '\\(', '\\*', '\\/', '\\2a ', '\\\n', '\\75 rl(',
    ];

    /**
     * Distinct fragments from the seed, keyed by an id that is also in each marker's URL. Each is a
     * <style> block, or one time in eight a <p> with a style attribute
     *
     * @return array<int, string>
     */
    public static function fragments(int $seed, int $samples): array
    {
        // single characters an edit inserts: printable ASCII, tab, newline, form feed and a few non-ASCII letters
        $characters = [...array_map(chr(...), range(0x20, 0x7E)), "\t", "\n", "\f", "\u{e9}", "\u{a0}", "\u{2028}", "\u{feff}"];
        $pick       = fn(array $list) => $list[array_keys($list)[mt_rand(0, count($list) - 1)]];   // mt_rand, whose seeded sequence PHP keeps the same across versions

        mt_srand($seed);
        $fragments = [];
        for ($i = 0; $i < $samples; $i++) {
            $css = $pick(self::SEEDS);
            for ($edits = mt_rand(1, 4); $edits > 0; $edits--) {
                $at     = mt_rand(0, strlen($css));
                $insert = mt_rand(0, 1) ? $pick($characters) : $pick(self::PIECES);
                $css    = match (mt_rand(0, 2)) {
                    0 => substr($css, 0, $at) . $insert . substr($css, $at),
                    1 => substr($css, 0, $at) . substr($css, $at + 1),
                    2 => substr($css, 0, $at) . $insert . substr($css, $at + 1),
                };
            }
            if (!mb_check_encoding($css, 'UTF-8')) {
                continue;
            }
            $inAttribute = mt_rand(0, 7) === 0;
            $marker      = $pick($inAttribute ? self::ATTRIBUTE_MARKERS : self::STYLE_MARKERS);
            $css         = mt_rand(0, 3) === 0 ? $marker . $css : $css . $marker;   // marker first sometimes: Chrome keeps @import only at the start
            $fragments[] = $inAttribute ? '<p title="abc" style="' . htmlspecialchars($css) . '">abc</p>' : "<style>$css</style>";
        }
        mt_srand();   // back to a random sequence for whatever runs next

        $fragments = array_values(array_unique($fragments));
        foreach ($fragments as $id => &$fragment) {
            $fragment = str_replace('__ID__', (string)$id, $fragment);
        }
        return $fragments;
    }
}
