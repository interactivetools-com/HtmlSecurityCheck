#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Runs the Chrome URL cross-check on random URL attributes for as long as you like: batches of
 * samples from tests/Support/UrlFuzzer.php, seeds counting up from a random start, and each batch
 * checked against how Chrome resolves the URLs (tests/Support/UrlCrossCheck.php). An accepted
 * fragment Chrome resolves to a scheme off the default $urlSchemes, an iframe host off the default
 * $iframeHosts, or javascript: on another attribute is a bypass. Forms are allowed for the run, so
 * action and formaction are tried too.
 *
 *     php tools/url-fuzz.php --log-dir=<dir>                           # 10 minutes from a random seed
 *     php tools/url-fuzz.php --log-dir=<dir> --minutes=90 --workers=8  # 8 processes, each with its own seeds
 *     php tools/url-fuzz.php --log-dir=<dir> --seed=812345             # one batch from that seed, to repeat a reported one
 *     php tools/url-fuzz.php --report=<dir>                            # totals from the logs a run left in <dir>
 *
 * Each batch appends one JSON line to <dir>/worker-<first seed>.jsonl as it finishes, so a run
 * that stops loses only the batch in progress, and --report adds up whatever is there.
 *
 * Refused fragments whose only errors are url-scheme-not-allowed or iframe-host go through Chrome
 * too: when Chrome resolves every URL in one to an allowed scheme and host, it is an over-refusal,
 * reported by shape with counts and the shortest examples. Those are expected, not bugs.
 *
 * Exits 1 when anything was flagged. Each flagged fragment is printed with its seed; shorten it by
 * hand, add a test for the check, and add it to UrlFuzzer's SEEDS. A Windows path (\\host\x, C|/x,
 * https:C:/x) is flagged only with a Windows Chrome, the one that reads it as a file: URL.
 */

require __DIR__ . '/../vendor/autoload.php';

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\ChromeCrossCheck;
use Itools\HtmlSecurityCheck\Tests\Support\UrlCrossCheck;
use Itools\HtmlSecurityCheck\Tests\Support\UrlFuzzer;

const URL_CODES = ['url-scheme-not-allowed', 'iframe-host'];
const EXAMPLES  = 5;

$options = getopt('', ['minutes:', 'seed:', 'workers:', 'samples:', 'log-dir:', 'report:', 'worker', 'step:']);
if (isset($options['report'])) {
    exit(report(glob($options['report'] . '/*.jsonl') ?: []));
}
$logDir  = $options['log-dir'] ?? exit("--log-dir=<dir> is required: each batch's results go there\n");
$seed    = isset($options['seed']) ? (int)$options['seed'] : random_int(1, 2 ** 31 - 1);
$minutes = isset($options['seed']) && !isset($options['minutes']) ? 0 : (float)($options['minutes'] ?? 10);
$workers = max(1, (int)($options['workers'] ?? 1));
$samples = (int)($options['samples'] ?? 100000);
is_dir($logDir) || mkdir($logDir, 0777, true);

if ($workers > 1) {
    exit(runWorkers($workers, $seed, $minutes, $samples, $logDir));
}
$log = "$logDir/worker-$seed.jsonl";
runBatches($seed, (int)($options['step'] ?? 1), $minutes, $samples, $log);
exit(isset($options['worker']) ? 0 : report([$log]));

/**
 * Start one process per worker, worker $i taking seeds $seed + $i, $seed + $i + $workers and so on, pass their output
 * through as it comes, then report on all their logs. Returns the exit code
 */
function runWorkers(int $workers, int $seed, float $minutes, int $samples, string $logDir): int
{
    $processes = [];
    $outputs   = [];
    for ($i = 0; $i < $workers; $i++) {
        $command     = [PHP_BINARY, __FILE__, '--worker', '--seed=' . ($seed + $i), "--step=$workers", "--minutes=$minutes", "--samples=$samples", "--log-dir=$logDir"];
        $pipes       = [];
        $processes[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $outputs[] = [$pipes[1], $pipes[2]];
    }
    while ($outputs) {
        $read   = array_merge(...array_values($outputs));
        $write  = $except = null;
        stream_select($read, $write, $except, 5);
        foreach ($outputs as $i => [$stdout, $stderr]) {
            foreach ([$stdout, $stderr] as $pipe) {
                while (($line = fgets($pipe)) !== false) {
                    echo $line;
                }
            }
            if (feof($stdout) && feof($stderr)) {
                fclose($stdout);
                fclose($stderr);
                proc_close($processes[$i]);
                unset($outputs[$i]);
            }
        }
    }
    $logs = array_map(fn(int $i) => "$logDir/worker-" . ($seed + $i) . '.jsonl', range(0, $workers - 1));
    return report(array_filter($logs, is_file(...)));
}

/**
 * Batches from $seed, $seed + $step and so on until $minutes are up (one batch when 0), each appended to $log as a JSON line
 */
function runBatches(int $seed, int $step, float $minutes, int $samples, string $log): void
{
    HtmlSecurityCheck::$allowForms      = true;
    HtmlSecurityCheck::$maxDetailLength = 10000;   // the whole value in a detail, for grouping over-refusals
    $chrome = ChromeCrossCheck::find() ?? exit("Chrome not found: install it or set CHROME_PATH\n");
    $stopAt = microtime(true) + $minutes * 60;
    do {
        $started   = microtime(true);
        $fragments = UrlFuzzer::fragments($seed, $samples);
        $accepted  = [];
        $refused   = [];
        $errors    = [];
        $other     = 0;
        foreach ($fragments as $id => $html) {
            $result = HtmlSecurityCheck::check($html);
            if ($result->ok) {
                $accepted[$id] = $html;
            } elseif (!array_diff(array_map(fn($error) => $error->code, $result->errors), URL_CODES)) {
                $refused[$id] = $html;
                $errors[$id]  = $result->errors;
            } else {
                $other++;
            }
        }
        $chromeResult = UrlCrossCheck::run($chrome, $accepted, $refused);

        $bypasses = [];
        foreach ($chromeResult['found'] as $id => $problems) {
            $bypasses[] = ['seed' => $seed, 'id' => $id, 'html' => $accepted[$id], 'problems' => $problems];
        }
        $shapes = [];
        foreach ($chromeResult['clean'] as $id) {
            $shape                       = overRefusalShape($errors[$id], $chromeResult['resolved'][$id] ?? []);
            $shapes[$shape]['count']     = ($shapes[$shape]['count'] ?? 0) + 1;
            $shapes[$shape]['examples'][] = $refused[$id];
        }
        foreach ($shapes as &$shape) {
            $shape['examples'] = shortest($shape['examples']);
        }
        unset($shape);

        $line = [
            'seed'           => $seed,
            'samples'        => $samples,
            'generated'      => count($fragments),
            'accepted'       => count($accepted),
            'refusedUrl'     => count($refused),
            'refusedOther'   => $other,
            'bypasses'       => $bypasses,
            'overRefusals'   => $shapes,
            'srcsetDisagree' => $chromeResult['srcsetDisagree'],
            'srcsetNotes'    => array_slice($chromeResult['srcsetNotes'], 0, 3),
            'seconds'        => round(microtime(true) - $started, 1),
        ];
        file_put_contents($log, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
        printf("seed %d: %s generated, %s accepted, %s refused for URLs, %d bypasses, %s over-refusals (%d s)\n", $seed,
            number_format($line['generated']), number_format($line['accepted']), number_format($line['refusedUrl']),
            count($bypasses), number_format(count($chromeResult['clean'])), $line['seconds']);
        foreach ($bypasses as $bypass) {
            echo '  BYPASS ', json_encode($bypass['html'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            foreach ($bypass['problems'] as [$kind, $text]) {
                echo "    Chrome: $kind $text\n";
            }
        }
        $seed += $step;
    } while (microtime(true) < $stopAt);
}

/**
 * Why Chrome found nothing wrong with a fragment the check refused, as a short label for grouping
 *
 * @param \Itools\HtmlSecurityCheck\Violation[] $errors
 * @param array<array{string, string, string, ?string, string}> $resolved [element, attribute, URL, href or null, attribute value] per URL
 */
function overRefusalShape(array $errors, array $resolved): string
{
    $codes = array_map(fn($error) => $error->code, $errors);
    if (in_array('iframe-host', $codes, true)) {
        foreach ($resolved as [$element, $attribute, $url, $href]) {
            if ($element === 'iframe' && $attribute === 'src') {
                return $href === null ? 'Chrome cannot parse the URL' : 'iframe: ' . iframeShape($url);
            }
        }
        return 'iframe: no src Chrome reads';
    }
    // the first url-scheme-not-allowed detail starts with the attribute name; its value is taken from Chrome,
    // since the detail has its control characters escaped
    $detail    = $errors[array_search('url-scheme-not-allowed', $codes, true)]->detail;
    $attribute = strstr($detail, '="', true);
    $mine      = array_filter($resolved, fn(array $url) => $url[1] === $attribute);
    if (in_array(null, array_column($mine, 3), true)) {
        return 'Chrome cannot parse the URL';
    }
    $value  = $mine ? reset($mine)[4] : '';
    $isList = $attribute === 'srcset' || $attribute === 'ping';
    foreach ($isList ? preg_split('/[\s,]++/', $value, -1, PREG_SPLIT_NO_EMPTY) : [$value] as $piece) {
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $piece);
        if (preg_match('~^(?:https?:)?+(?:\\\\[\\\\/]|/\\\\|[a-z][:|])~i', $compact)) {   // \\host, \/host, /\host or C: or C|, also after http: or https:
            return 'a Windows path, refused on purpose: Windows opens it as a file';
        }
        if (!preg_match('/^([a-z][a-z0-9+.\-]*+):/i', $compact, $scheme) || in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            continue;   // not the piece the check refused
        }
        $chromeView = str_replace(["\t", "\n", "\r"], '', trim($piece, "\x00..\x20"));
        if (!preg_match('/^[a-z][a-z0-9+.\-]*+:/i', $chromeView)) {
            preg_match('/[\x00-\x20\x7F]/', $chromeView, $kept);
            $name = match ($kept[0] ?? '') {
                ' '     => 'a space',
                "\x0B"  => 'a vertical tab',
                "\x0C"  => 'a form feed',
                "\x7F"  => 'a DEL',
                default => 'another C0 control',
            };
            return "$name inside the scheme, which Chrome keeps, so the URL has no scheme";
        }
        if ($isList) {
            $at     = strpos($value, $piece);
            $before = $at > 0 ? $value[$at - 1] : '';
            return match (true) {
                $before === ','                            => "$attribute: the check splits at a comma inside a URL",
                $before === "\x0B"                         => "$attribute: the check splits at a vertical tab inside a URL",
                $attribute === 'srcset' && $before !== '' => 'srcset: a descriptor the check reads as a URL',
                default                                    => "$attribute: other",
            };
        }
        if (strtolower($scheme[1]) === 'data' && $attribute === 'src') {
            return 'img src: data: that is not data:image/';
        }
    }
    return 'other';
}

/**
 * What about an iframe src Chrome resolves to a listed host the check's pattern did not match
 */
function iframeShape(string $url): string
{
    $src = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));
    preg_match('~^(?:https?:)?([\\\\/]*+)([^\\\\/?#]*+)(.?)~i', $src, $parts);
    [, $slashes, $authority, $after] = $parts;
    return match (true) {
        str_contains($slashes, '\\')                           => 'a backslash in place of a slash',
        strlen($slashes) !== 2                                 => 'http: or https: with other than two slashes, which Chrome reads as the start of the host anyway',
        str_contains($authority, '@')                          => 'userinfo before a listed host',
        (bool)preg_match('~:\d*+$~', $authority)              => 'the default port or an empty one',
        str_contains($authority, '%')                          => 'a percent-escaped host',
        (bool)preg_match('/[^\x00-\x7F]/', $authority)        => 'a host with a character IDNA drops or maps (soft hyphen, zero-width, full-width)',
        $after === '\\'                                        => 'a backslash after the host',
        default                                                => 'other',
    };
}

/**
 * The shortest few, shortest first
 *
 * @param string[] $list
 * @return string[]
 */
function shortest(array $list): array
{
    $list = array_values(array_unique($list));
    usort($list, fn(string $a, string $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
    return array_slice($list, 0, EXAMPLES);
}

/**
 * Totals, bypasses and over-refusals by shape from the JSON lines in $logs. Returns 1 when there was a bypass
 *
 * @param string[] $logs
 */
function report(array $logs): int
{
    $totals   = ['batches' => 0, 'generated' => 0, 'accepted' => 0, 'refusedUrl' => 0, 'refusedOther' => 0, 'srcsetDisagree' => 0, 'seconds' => 0];
    $bypasses = [];
    $shapes   = [];
    $notes    = [];
    $seeds    = [];
    foreach ($logs as $log) {
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $json) {
            $line = json_decode($json, true);
            if (!is_array($line)) {
                continue;   // a line cut off when the run stopped
            }
            $totals['batches']++;
            foreach (['generated', 'accepted', 'refusedUrl', 'refusedOther', 'srcsetDisagree', 'seconds'] as $key) {
                $totals[$key] += $line[$key];
            }
            $seeds[]  = $line['seed'];
            $bypasses = [...$bypasses, ...$line['bypasses']];
            $notes    =array_slice([...$notes, ...$line['srcsetNotes']], 0, 5);
            foreach ($line['overRefusals'] as $shape => $group) {
                $shapes[$shape]['count']    = ($shapes[$shape]['count'] ?? 0) + $group['count'];
                $shapes[$shape]['examples'] = shortest([...$shapes[$shape]['examples'] ?? [], ...$group['examples']]);
            }
        }
    }
    sort($seeds);
    $json = fn(string $html) => json_encode($html, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    printf("\n%d batches, seeds %s, %s fragments generated, %s accepted, %s refused for URL reasons only, %s refused otherwise, %s batch-seconds\n",
        $totals['batches'], compactSeeds($seeds), number_format($totals['generated']), number_format($totals['accepted']),
        number_format($totals['refusedUrl']), number_format($totals['refusedOther']), number_format($totals['seconds']));
    printf("%d bypasses\n", count($bypasses));
    foreach ($bypasses as $bypass) {
        echo "  seed {$bypass['seed']} #{$bypass['id']}: ", $json($bypass['html']), "\n";
        foreach ($bypass['problems'] as [$kind, $text]) {
            echo "    Chrome: $kind $text\n";
        }
    }
    printf("%s srcset or ping values where a URL Chrome reads is not one of the check's pieces, for example:\n", number_format($totals['srcsetDisagree']));
    foreach ($notes as $note) {
        echo "  $note\n";
    }
    // a URL Chrome cannot parse loads nothing, but it is not one Chrome resolves to an allowed scheme, so it is counted on its own
    $unparsed = $shapes['Chrome cannot parse the URL'] ?? ['count' => 0, 'examples' => []];
    unset($shapes['Chrome cannot parse the URL']);
    printf("%s refused for a URL Chrome cannot parse at all, shortest:\n", number_format($unparsed['count']));
    foreach ($unparsed['examples'] as $html) {
        echo '  ', $json($html), "\n";
    }
    uasort($shapes, fn(array $a, array $b) => $b['count'] <=> $a['count']);
    printf("%s over-refusals (refused for a URL, every URL resolves to an allowed scheme and host in Chrome), by shape:\n", number_format(array_sum(array_column($shapes, 'count'))));
    foreach ($shapes as $shape => $group) {
        printf("  %s: %s\n", $shape, number_format($group['count']));
        foreach ($group['examples'] as $html) {
            echo '    ', $json($html), "\n";
        }
    }
    return $bypasses ? 1 : 0;
}

/**
 * Seeds as ranges, "5-9, 12"
 *
 * @param int[] $seeds sorted
 */
function compactSeeds(array $seeds): string
{
    $ranges = [];
    foreach ($seeds as $seed) {
        $last = array_key_last($ranges);
        if ($last !== null && $ranges[$last][1] === $seed - 1) {
            $ranges[$last][1] = $seed;
        } else {
            $ranges[] = [$seed, $seed];
        }
    }
    return implode(', ', array_map(fn(array $range) => $range[0] === $range[1] ? (string)$range[0] : "$range[0]-$range[1]", $ranges)) ?: 'none';
}
