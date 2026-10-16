#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Runs the Chrome cross-check's CSS fuzz for as long as you like, with new seeds: batches of
 * 300,000 samples from tests/Support/CssFuzzer.php, seeds counting up from a random start, each
 * batch's accepted fragments checked against the CSS Chrome parsed (tests/Support/ChromeCrossCheck.php).
 * ChromeCrossCheckTest runs one small batch from a fixed seed on every test run; this is the long run
 * to start before a release or after changing a CSS rule.
 *
 *     php tools/css-fuzz.php                    # 10 minutes from a random seed
 *     php tools/css-fuzz.php --minutes=300      # 5 hours
 *     php tools/css-fuzz.php --seed=812345      # one batch from that seed, to repeat a reported one
 *
 * The fragments Chrome flags in a batch are then rendered in a page served by PHP's built-in server
 * through tools/css-fuzz-log.php, to show what each does in a browser: a selector marker that turns
 * the page's own <p title="abc"> red, or a marker that sends a request (a background image, @import,
 * a font) the server logs. A flagged fragment that does neither is still a place where the check and
 * Chrome read the CSS differently.
 *
 * Exits 1 when anything was flagged. Each flagged fragment is printed with its seed; shorten it by
 * hand, add a test for the check, and add it to CssFuzzer's SEEDS so every later run tries it again.
 */

require __DIR__ . '/../vendor/autoload.php';

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\ChromeCrossCheck;
use Itools\HtmlSecurityCheck\Tests\Support\CssFuzzer;

const SAMPLES_PER_BATCH = 300000;

$options = getopt('', ['minutes:', 'seed:']);
$seed    = isset($options['seed']) ? (int)$options['seed'] : random_int(1, 2 ** 31 - 1);
$minutes = isset($options['seed']) && !isset($options['minutes']) ? 0 : (float)($options['minutes'] ?? 10);
$chrome  = ChromeCrossCheck::find() ?? exit("Chrome not found: install it or set CHROME_PATH\n");

$stopAt   = microtime(true) + $minutes * 60;
$accepted = 0;
$flagged  = 0;
$batches  = 0;
do {
    $started = microtime(true);
    $cases   = [];
    foreach (CssFuzzer::fragments($seed, SAMPLES_PER_BATCH) as $id => $html) {
        if (HtmlSecurityCheck::check($html)->ok) {
            $cases[$id] = $html;
        }
    }
    $findings = ChromeCrossCheck::findings($chrome, $cases);
    printf("seed %d: %s accepted, %d flagged (%d s)\n", $seed, number_format(count($cases)), count($findings), microtime(true) - $started);
    if ($findings) {
        $rendered = render($chrome, array_intersect_key($cases, $findings));
        foreach ($findings as $id => $found) {
            echo '  ', json_encode($cases[$id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            foreach ($found as $finding) {
                echo '    Chrome parsed: ', substr(strstr($finding, ': '), 2), "\n";
            }
            foreach ($rendered[$id] ?? [] as $line) {
                echo "    Rendered: $line\n";
            }
        }
    }
    $accepted += count($cases);
    $flagged  += count($findings);
    $batches++;
    $seed++;
} while (microtime(true) < $stopAt);

printf("\n%d batches, %s accepted fragments, %d flagged\n", $batches, number_format($accepted), $flagged);
exit($flagged ? 1 : 0);

/**
 * What each fragment did when rendered next to <p title="abc"> in a page served through the request
 * logger: "the selector matched the page's own title attribute" and "requested /<marker>/<id>" lines
 *
 * @param array<int, string> $cases id => fragment, the id being the one in the fragment's marker URLs
 * @return array<int, string[]>
 */
function render(string $chrome, array $cases): array
{
    $dir = sys_get_temp_dir() . '/htmlcheck-css-fuzz-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $json = json_encode($cases, JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    file_put_contents("$dir/page.html", str_replace('__CASES__', $json, file_get_contents(__DIR__ . '/css-fuzz-page.html')));
    touch("$dir/requests.log");

    $socket = stream_socket_server('tcp://127.0.0.1:0');   // a free port
    $port   = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $pipes  = [];
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, __DIR__ . '/css-fuzz-log.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($tries = 0; $tries < 50 && !@fsockopen('127.0.0.1', $port); $tries++) {
        usleep(100_000);
    }
    try {
        $dom = ChromeCrossCheck::dumpDom($chrome, "http://127.0.0.1:$port/page.html", ['--virtual-time-budget=3600000']);
        usleep(500_000);   // the server may still be writing the last requests
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
    $requests = file("$dir/requests.log", FILE_IGNORE_NEW_LINES);
    array_map(unlink(...), glob("$dir/*"));
    rmdir($dir);

    preg_match('~<pre id="out">([^<]*)</pre>~', $dom, $match);
    $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5), true);
    if (!isset($result['red'])) {
        return array_fill_keys(array_keys($cases), ['the page that renders it did not run: ' . ($match[1] ?? 'no result in the dumped DOM')]);
    }
    $rendered = [];
    foreach ($result['red'] as $id) {
        $rendered[(int)$id][] = "the selector matched the page's own title attribute";
    }
    foreach ($requests as $path) {
        if (preg_match('~^/\w+/(\d+)$~', $path, $request)) {   // the markers request /<marker>/<id>
            $rendered[(int)$request[1]][] = "requested $path";
        }
    }
    return $rendered;
}
