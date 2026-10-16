<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

use FilesystemIterator;
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Runs tests/Support/chrome-cross-check.html in headless Chrome: the page parses each case with
 * DOMParser and reports any script element, on* attribute or javascript: URL in the tree, and
 * anything the check refuses in the CSS Chrome parsed. ChromeCrossCheckTest and tools/css-fuzz.php
 * both use it.
 *
 *     $chrome   = ChromeCrossCheck::find();                       // null when Chrome is not installed
 *     $findings = ChromeCrossCheck::findings($chrome, $cases);    // ['case name' => ['case name: <script>', ...]]
 *
 * Canaries go through the page with every run, one for each thing it looks for, and each must be
 * flagged, so a page that stopped finding anything throws instead of reporting nothing.
 */
final class ChromeCrossCheck
{
    private const HARNESS  = __DIR__ . '/chrome-cross-check.html';
    private const FIXTURES = __DIR__ . '/fixtures';

    // a <script>, an on* attribute and a javascript: URL behind an entity: one reject fixture per finding the page reports
    private const CANARIES = ['element-not-allowed-1.html', 'event-handler-1.html', 'url-scheme-not-allowed-1.html'];

    // one CSS canary per thing the page looks for in the CSS Chrome parsed
    private const CSS_CANARIES = [
        'canary css selector'      => '<style>[title^=a]{color:red}</style>',
        'canary css at-rule'       => '<style>@import url(x.css);</style>',
        'canary css unicode-range' => '<style>@font-face{font-family:f;src:url(f.woff);unicode-range:U+61}</style>',
        'canary css function'      => '<p style="background:image-set(url(a.png) 1x)">x</p>',
        'canary css url scheme'    => '<p style="background:url(data:image/png;base64,AAAA)">x</p>',
    ];

    // the attributes [attr^=], [attr$=] and [attr*=] may test, as the check's CSS_FORBIDDEN pattern lists them
    private const PREFIX_SELECTOR_ATTRIBUTES = ['style', 'src', 'class'];

    private const CHROME_NAMES = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'];

    // where Chrome installs when it is not on PATH: macOS, Windows, and Windows as seen from WSL
    private const CHROME_PATHS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
        '/mnt/c/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    ];

    /**
     * The Chrome binary from CHROME_PATH, the usual names on PATH, or the install locations; null when none is found
     */
    public static function find(): ?string
    {
        $candidates = [getenv('CHROME_PATH') ?: ''];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            foreach (self::CHROME_NAMES as $name) {
                $candidates[] = "$dir/$name";
            }
        }
        foreach ([...$candidates, ...self::CHROME_PATHS] as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * What Chrome found in the cases, grouped by case name; an empty array when it found nothing
     *
     * @param array<string, string> $cases case name => HTML
     * @return array<string, string[]> case name => findings, each "case name: what was found"
     * @throws RuntimeException when Chrome or the page did not run, or a canary went unflagged
     */
    public static function findings(string $chrome, array $cases): array
    {
        $canaries = self::CSS_CANARIES;
        foreach (self::CANARIES as $file) {
            $canaries["canary $file"] = file_get_contents(self::FIXTURES . "/reject/$file");
        }
        $result = self::run($chrome, $cases + $canaries);
        if ($result['checked'] !== count($cases) + count($canaries)) {
            throw new RuntimeException("the page checked {$result['checked']} cases, not " . (count($cases) + count($canaries)));
        }
        $flagged = [];
        foreach ($result['found'] as $finding) {
            $flagged[strstr($finding, ': ', true)][] = $finding;
        }
        $missed = array_keys(array_diff_key($canaries, $flagged));
        if ($missed) {
            throw new RuntimeException('the page no longer flags these known payloads: ' . implode(', ', $missed));
        }
        return array_diff_key($flagged, $canaries);
    }

    /**
     * Run the page on the cases in one headless Chrome
     *
     * @param array<string, string> $cases
     * @return array{checked: int, found: string[]}
     */
    private static function run(string $chrome, array $cases): array
    {
        $dir = sys_get_temp_dir() . '/htmlcheck-chrome-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $page = "$dir/cross-check.html";
        $css  = [
            'atRules'          => HtmlSecurityCheck::CSS_AT_RULES,
            'functions'        => HtmlSecurityCheck::CSS_FUNCTIONS,
            'urlSchemes'       => HtmlSecurityCheck::CSS_URL_SCHEMES,
            'prefixAttributes' => self::PREFIX_SELECTOR_ATTRIBUTES,
        ];
        file_put_contents($page, strtr(file_get_contents(self::HARNESS), ['__CASES__' => self::json($cases), '__CSS__' => self::json($css)]));
        try {
            $dom = self::dumpDom($chrome, self::pathForChrome($chrome, $page));
        } finally {
            self::removeDir($dir);
        }
        if (!preg_match('~<pre id="out">([^<]*)</pre>~', $dom, $match)) {
            throw new RuntimeException('no result in the dumped DOM');
        }
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
        if (!is_array($result)) {
            throw new RuntimeException('the harness page did not run: ' . $match[1]);
        }
        return $result;
    }

    /**
     * The DOM of a page after headless Chrome loads it, with a fresh profile so it never attaches to
     * a browser that is already open
     *
     *     $dom = ChromeCrossCheck::dumpDom($chrome, 'http://127.0.0.1:8099/page.html', ['--virtual-time-budget=60000']);
     *
     * @param string $page a URL, or a file path as Chrome reads it
     * @param string[] $flags more Chrome flags
     * @throws RuntimeException when Chrome does not start or exits with an error
     */
    public static function dumpDom(string $chrome, string $page, array $flags = []): string
    {
        $profile = sys_get_temp_dir() . '/htmlcheck-chrome-profile-' . bin2hex(random_bytes(4));
        try {
            $command = [
                $chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run',
                '--user-data-dir=' . self::pathForChrome($chrome, $profile), ...$flags, '--dump-dom', $page,
            ];
            $pipes   = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('could not start Chrome: ' . $chrome);
            }
            $dom = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } finally {
            if (is_dir($profile)) {
                self::removeDir($profile);
            }
        }
        if ($exit !== 0) {
            throw new RuntimeException("Chrome did not exit cleanly: exit code $exit");
        }
        return $dom;
    }

    /**
     * The value as JSON with no < > or & in it, so a </script> inside a case cannot end the script block
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * The path as Chrome will read it: a Windows path when a Windows Chrome runs from WSL
     */
    public static function pathForChrome(string $chrome, string $path): string
    {
        if (PHP_OS_FAMILY === 'Linux' && str_ends_with($chrome, '.exe')) {
            return trim((string)shell_exec('wslpath -w ' . escapeshellarg($path)));
        }
        return $path;
    }

    /**
     * Delete the page and the Chrome profile, which is a few MB per run
     */
    private static function removeDir(string $dir): void
    {
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path => $info) {
            $info->isDir() && !$info->isLink() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
