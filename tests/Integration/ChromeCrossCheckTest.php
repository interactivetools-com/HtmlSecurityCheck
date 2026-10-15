<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Integration;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\ChromeCrossCheck;
use Itools\HtmlSecurityCheck\Tests\Support\CssFuzzer;
use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;

/**
 * A second opinion from a real browser: every fragment the check accepts is parsed by Chrome, and
 * the tree Chrome builds must hold no script element, no on* attribute and no javascript: URL. The
 * CSS Chrome parsed from each <style> and style attribute, read back from its stylesheets, must hold
 * nothing the check refuses: an at-rule or function off its allowlist, a url() scheme other than
 * http or https, an [attr^=], [attr$=] or [attr*=] selector, or unicode-range. The rules read our
 * own tokenizer's tokens and our own CSS scans; this is the test that fails the day either reads
 * something differently from Blink.
 *
 * One headless Chrome run per test does it all (tests/Support/ChromeCrossCheck.php): the fragments
 * go into tests/Support/chrome-cross-check.html as JSON, the page parses each with DOMParser and
 * walks the tree, and the dumped DOM carries the findings back. A DOMParser document has scripting
 * off, so <noscript> content is parsed as markup, and the walk enters <template> content: neither
 * hides anything from it. Canaries go through the same page, so a page that stopped finding
 * anything fails too.
 *
 * The fragments are the accept and tinymce4 fixtures, the html5lib tokenizer inputs and the corpus
 * when it is present, and in a second test CssFuzzer's random CSS from a fixed seed, so a failure
 * there repeats on every run. tools/css-fuzz.php runs the same fuzzer for hours with new seeds.
 *
 * Chrome is found through CHROME_PATH, the usual names on PATH, or the install locations. In CI
 * the tests run on the Linux jobs only, and fail there without Chrome except on ARM, where Google
 * ships none. Elsewhere they skip when Chrome is missing.
 */
final class ChromeCrossCheckTest extends HtmlSecurityCheckTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';
    private const CORPUS   = __DIR__ . '/../../corpus';

    // the fuzz run: about 5,200 accepted fragments, under 4 seconds
    private const FUZZ_SEED    = 1;
    private const FUZZ_SAMPLES = 50000;

    public function testChromeFindsNoScriptOrRefusedCssInAcceptedContent(): void
    {
        $chrome   = $this->chrome();
        $accepted = self::acceptedContent();
        $this->assertGreaterThan(1000, count($accepted));
        $this->assertSame([], ChromeCrossCheck::findings($chrome, $accepted), 'Chrome found script or refused CSS in content the check accepted');
    }

    public function testChromeFindsNoRefusedCssInFuzzedCssTheCheckAccepts(): void
    {
        $chrome   = $this->chrome();
        $accepted = [];
        foreach (CssFuzzer::fragments(self::FUZZ_SEED, self::FUZZ_SAMPLES) as $id => $html) {
            if (HtmlSecurityCheck::check($html)->ok) {
                $accepted["css fuzz #$id"] = $html;
            }
        }
        $this->assertGreaterThan(1000, count($accepted));
        $failures = [];
        foreach (array_slice(ChromeCrossCheck::findings($chrome, $accepted), 0, 20) as $name => $findings) {
            $failures[$accepted[$name]] = $findings;
        }
        $this->assertSame([], $failures, 'Chrome found CSS the check refuses in fuzzed CSS the check accepted (first 20)');
    }

    /**
     * The Chrome binary; skips the test, or fails it in CI on a platform Google ships Chrome for, when there is none
     */
    private function chrome(): string
    {
        if (getenv('CI') && PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('runs on the Linux CI jobs only: Chrome parses the same on every platform');
        }
        $chrome = ChromeCrossCheck::find();
        if ($chrome === null) {
            $message           = 'Chrome not found: install it or set CHROME_PATH to run the browser cross-check';
            $platformHasChrome = PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'aarch64';   // Google ships no Chrome for Linux on ARM
            if (getenv('CI') && $platformHasChrome) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        return $chrome;
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
}
