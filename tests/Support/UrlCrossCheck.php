<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use RuntimeException;

/**
 * Runs tests/Support/url-cross-check.html in headless Chrome: the page parses each case with
 * DOMParser and resolves every URL attribute with new URL(value, 'https://example.test/dir/page.html'),
 * the value being the one Chrome decoded. A scheme off the default $urlSchemes, a file: URL, an
 * <iframe> whose src is not http or https on a default $iframeHosts host and port, and javascript:
 * on any other attribute are reported. tools/url-fuzz.php uses it.
 *
 *     $chrome = ChromeCrossCheck::find();
 *     $result = UrlCrossCheck::run($chrome, $accepted, $refused);
 *     $result['found'];   // [id => [[kind, text], ...]] for the accepted cases Chrome found something in
 *     $result['clean'];   // ids of the refused cases whose every URL Chrome resolved to an allowed one
 *     $result['resolved'];   // [id => [[element, attribute, URL, href or null, attribute value], ...]] for the clean ids, or every id when verbose
 *
 * A file: URL from a Windows path (\\server\x, C|/x, https:C:/x) is reported as kind windows-path,
 * any other file: URL as kind file. Chrome reads a Windows path as a file: URL only on Windows, so
 * on Linux the page sees a relative URL. srcset is split as the HTML Standard says, and srcsetDisagree counts the
 * srcset and ping values where a URL Chrome reads is not one of the pieces the check splits it into.
 *
 * Canaries go through the page with every run and must come out as expected, so a page that
 * stopped finding anything throws instead of reporting nothing.
 */
final class UrlCrossCheck
{
    private const HARNESS = __DIR__ . '/url-cross-check.html';

    // accepted-side canaries, each of which the page must flag, by the kind it must flag
    private const FLAGGED_CANARIES = [
        'canary javascript href' => ['scheme', '<a href="javascript:alert(1)">x</a>'],
        'canary entity scheme'   => ['scheme', '<a href="jav&#x61;script&colon;alert(1)">x</a>'],
        'canary iframe host'     => ['iframe', '<iframe src="https://evil.test/"></iframe>'],
        'canary iframe port'     => ['iframe', '<iframe src="https://www.youtube.com:8080/"></iframe>'],
        'canary srcset'          => ['scheme', '<img srcset="a.png 1x, javascript:alert(1) 2x">'],
        'canary ping'            => ['scheme', '<a href="/" ping="/a vbscript:x">x</a>'],
        'canary title'           => ['script', '<p title="javascript:x">x</p>'],
    ];

    // canaries the page must not flag: allowed URLs, and refused-side ones it must call clean
    private const CLEAN_CANARIES = [
        'canary relative'      => '<a href="/page">x</a>',
        'canary allowed frame' => '<iframe src="https://www.youtube.com/embed/x"></iframe>',
        'canary img data'      => '<img src="data:image/png;base64,AAAA">',
        'canary srcset ok'     => '<img srcset="a.png 1x, //cdn.example/b.png 2x">',
        'canary space scheme'  => '<a href="java script:x">x</a>',
    ];

    /**
     * Run the page on the accepted and refused cases in one headless Chrome
     *
     * @param array<int|string, string> $accepted id => fragment, reported in found
     * @param array<int|string, string> $refused  id => fragment, reported in clean when Chrome finds nothing
     * @param bool $verbose return the resolved URLs of every case, not only the clean refused ones
     * @return array{found: array<string, array<array{string, string}>>, clean: string[], resolved: array<string, array<array{string, string, string, ?string, string}>>, srcsetDisagree: int, srcsetNotes: string[]}
     * @throws RuntimeException when Chrome or the page did not run, or a canary came out wrong
     */
    public static function run(string $chrome, array $accepted, array $refused, bool $verbose = false): array
    {
        $flagged = array_map(fn(array $canary) => $canary[1], self::FLAGGED_CANARIES);
        $result  = self::runPage($chrome, $accepted + $flagged + self::CLEAN_CANARIES, $refused + self::CLEAN_CANARIES, $verbose);
        $expect  = count($accepted) + count($refused) + count($flagged) + 2 * count(self::CLEAN_CANARIES);
        if ($result['checked'] !== $expect) {
            throw new RuntimeException("the page checked {$result['checked']} cases, not $expect");
        }
        $wrong = [];
        foreach (self::FLAGGED_CANARIES as $name => [$kind]) {
            if (!in_array($kind, array_column($result['found'][$name] ?? [], 0), true)) {
                $wrong[] = "$name not flagged as $kind";
            }
        }
        foreach (array_keys(self::CLEAN_CANARIES) as $name) {
            if (isset($result['found'][$name]) || !in_array($name, $result['clean'], true)) {
                $wrong[] = "$name flagged";
            }
        }
        if ($wrong) {
            throw new RuntimeException('the page got its canaries wrong: ' . implode(', ', $wrong));
        }
        $canaries = $flagged + self::CLEAN_CANARIES;
        return [
            'found'          => array_diff_key($result['found'], $canaries),
            'clean'          => array_values(array_diff($result['clean'], array_keys($canaries))),
            'resolved'       => array_diff_key($result['resolved'], $canaries),
            'srcsetDisagree' => $result['srcsetDisagree'],
            'srcsetNotes'    => $result['srcsetNotes'],
        ];
    }

    /**
     * @return array{checked: int, found: array<string, array<array{string, string}>>, clean: string[], resolved: array<string, array<array{string, string, string, ?string, string}>>, srcsetDisagree: int, srcsetNotes: string[]}
     */
    private static function runPage(string $chrome, array $accepted, array $refused, bool $verbose): array
    {
        $dir = sys_get_temp_dir() . '/htmlcheck-url-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $page  = "$dir/url-cross-check.html";
        $cases = ['accepted' => (object)$accepted, 'refused' => (object)$refused, 'verbose' => $verbose];
        $rules = [
            'urlAttributes' => HtmlSecurityCheck::URL_ATTRIBUTES,
            'urlSchemes'    => ['http', 'https', 'mailto', 'tel'],   // the defaults, whatever the caller set
            'iframeHosts'   => ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'],
            'scriptSchemes' => HtmlSecurityCheck::SCRIPT_SCHEMES,
        ];
        file_put_contents($page, strtr(file_get_contents(self::HARNESS), ['__CASES__' => self::json($cases), '__RULES__' => self::json($rules)]));
        try {
            $dom = ChromeCrossCheck::dumpDom($chrome, ChromeCrossCheck::pathForChrome($chrome, $page));
        } finally {
            @unlink($page);
            @rmdir($dir);
        }
        if (!preg_match('~<pre id="out">([^<]*)</pre>~', $dom, $match)) {
            throw new RuntimeException('no result in the dumped DOM');
        }
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
        if (!is_array($result)) {
            throw new RuntimeException('the harness page did not run: ' . substr($match[1], 0, 500));
        }
        return $result;
    }

    /**
     * The value as JSON with no < > or & in it, so a </script> inside a case cannot end the script block
     */
    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
