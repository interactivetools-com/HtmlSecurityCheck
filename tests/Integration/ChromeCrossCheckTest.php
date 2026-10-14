<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Integration;

use FilesystemIterator;
use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A second opinion from a real browser: every fragment the check accepts is parsed by Chrome, and
 * the tree Chrome builds must hold no script element, no on* attribute and no javascript: URL. The
 * rules read our own tokenizer's tokens; this is the test that fails the day it reads a tag
 * differently from Blink.
 *
 * One headless Chrome run does it all: the fragments (the accept and tinymce4 fixtures, the html5lib
 * tokenizer inputs, and the corpus when it is present) go into tests/Support/chrome-cross-check.html
 * as JSON, the page parses each with DOMParser and walks the tree, and the dumped DOM carries the
 * findings back. A DOMParser document has scripting off, so <noscript> content is parsed as markup,
 * and the walk enters <template> content: neither hides anything from it.
 *
 * Three reject fixtures go through the same page as canaries, one for each thing the page looks
 * for, and each must be flagged, so a page that stopped finding anything fails too.
 *
 * Chrome is found through CHROME_PATH, the usual names on PATH, or the install locations. In CI
 * the test runs on the Linux jobs only, and fails there without Chrome except on ARM, where Google
 * ships none. Elsewhere it skips when Chrome is missing.
 */
final class ChromeCrossCheckTest extends HtmlSecurityCheckTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';
    private const CORPUS   = __DIR__ . '/../../corpus';
    private const HARNESS  = __DIR__ . '/../Support/chrome-cross-check.html';

    // a <script>, an on* attribute and a javascript: URL behind an entity: one reject fixture per finding the page reports
    private const CANARIES = ['element-not-allowed-1.html', 'event-handler-1.html', 'url-scheme-not-allowed-1.html'];

    private const CHROME_NAMES = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'];

    // where Chrome installs when it is not on PATH: macOS, Windows, and Windows as seen from WSL
    private const CHROME_PATHS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
        '/mnt/c/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    ];

    public function testChromeFindsNoScriptInAcceptedContent(): void
    {
        if (getenv('CI') && PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('runs on the Linux CI jobs only: Chrome parses the same on every platform');
        }
        $chrome = self::chrome();
        if ($chrome === null) {
            $message           = 'Chrome not found: install it or set CHROME_PATH to run the browser cross-check';
            $platformHasChrome = PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'aarch64';   // Google ships no Chrome for Linux on ARM
            if (getenv('CI') && $platformHasChrome) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        $accepted = self::acceptedContent();
        $this->assertGreaterThan(1000, count($accepted));
        $canaries = [];
        foreach (self::CANARIES as $file) {
            $canaries["canary $file"] = file_get_contents(self::FIXTURES . "/reject/$file");
        }
        $result = $this->runInChrome($chrome, $accepted + $canaries);

        $this->assertSame(count($accepted) + count($canaries), $result['checked']);
        $flagged = [];
        foreach ($result['found'] as $finding) {
            $flagged[strstr($finding, ': ', true)][] = $finding;   // each finding is "case name: what was found"
        }
        $this->assertSame([], array_keys(array_diff_key($canaries, $flagged)), 'the page no longer flags these known payloads');
        $this->assertSame([], array_diff_key($flagged, $canaries), 'Chrome found script in content the check accepted');
    }

    /**
     * Run the harness page on the cases in one headless Chrome, with a fresh profile so it never
     * attaches to a browser that is already open
     *
     * @param array<string, string> $cases
     * @return array{checked: int, found: string[]}
     */
    private function runInChrome(string $chrome, array $cases): array
    {
        $dir = sys_get_temp_dir() . '/htmlcheck-chrome-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $page = "$dir/cross-check.html";
        file_put_contents($page, str_replace('__CASES__', self::json($cases), file_get_contents(self::HARNESS)));
        try {
            [$dom, , $exit] = $this->runCommand([
                $chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run',
                '--user-data-dir=' . self::pathForChrome($chrome, "$dir/profile"),
                '--dump-dom', self::pathForChrome($chrome, $page),
            ]);
        } finally {
            self::removeDir($dir);
        }
        $this->assertSame(0, $exit, 'Chrome did not exit cleanly');
        $this->assertSame(1, preg_match('~<pre id="out">([^<]*)</pre>~', $dom, $match), 'no result in the dumped DOM');
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($result, 'the harness page did not run: ' . $match[1]);
        return $result;
    }

    /**
     * Every fixture, html5lib input and corpus file the check accepts, keyed by a name for the report
     *
     * @return array<string, string>
     */
    private static function acceptedContent(): array
    {
        $content = [];
        foreach (glob(self::FIXTURES . '/{accept,tinymce4}/*.html', GLOB_BRACE) as $path) {
            $content[basename($path)] = file_get_contents($path);
        }
        foreach (glob(self::FIXTURES . '/html5lib/*.test') as $file) {
            if (str_ends_with($file, 'xmlViolation.test')) {
                continue;   // an XML-compatibility mode, not the HTML tokenizer, and keyed differently
            }
            $doc = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($doc['tests'] as $index => $test) {
                $input = ($test['doubleEscaped'] ?? false) ? json_decode('"' . $test['input'] . '"') : $test['input'];
                if (is_string($input)) {
                    $content[basename($file) . " #$index"] = $input;
                }
            }
        }
        if (is_dir(self::CORPUS)) {
            foreach (glob(self::CORPUS . '/*/{*,*/*}.html', GLOB_BRACE) as $path) {
                $content[substr($path, strlen(self::CORPUS) + 1)] = file_get_contents($path);
            }
        }
        return array_filter($content, fn(string $html) => HtmlSecurityCheck::check($html)->ok);
    }

    /**
     * The cases as JSON with no < > or & in it, so a </script> inside a case cannot end the script block
     */
    private static function json(array $cases): string
    {
        return json_encode($cases, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * The Chrome binary, or null when none is found
     */
    private static function chrome(): ?string
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
     * The path as Chrome will read it: a Windows path when a Windows Chrome runs from WSL
     */
    private static function pathForChrome(string $chrome, string $path): string
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
