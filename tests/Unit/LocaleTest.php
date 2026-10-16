<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rules hold under whatever LC_CTYPE the site sets. Under a Turkish or Azeri locale PCRE's /i
 * no longer matches I to i, so the check runs under the C locale and puts the caller's back after.
 *
 * The test needs a Turkish or Azeri locale installed (CI installs tr_TR.UTF-8); it skips without one.
 */
final class LocaleTest extends HtmlSecurityCheckTestCase
{
    // the names a Turkish or Azeri locale goes by on Linux, macOS and Windows
    private const LOCALES = ['tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR.ISO-8859-9', 'tr_TR', 'az_AZ.UTF-8', 'Turkish_Turkey.1254'];

    #[DataProvider('capitalIProvider')]
    public function testACapitalIIsReadAsIUnderATurkishLocale(string $html, string $code, string $detail): void
    {
        $previous = setlocale(LC_CTYPE, '0');
        $turkish  = setlocale(LC_CTYPE, self::LOCALES);
        if ($turkish === false) {
            $this->markTestSkipped('No Turkish or Azeri locale installed');
        }
        try {
            $this->assertRejects($html, $code, $detail);
            $this->assertSame($turkish, setlocale(LC_CTYPE, '0'), "the caller's locale is put back");
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    }

    public static function capitalIProvider(): array
    {
        return [
            'event handler'    => ['<IMG src=x onerror=alert(1)>', 'event-handler', 'onerror'],
            'script scheme'    => ['<a href="javascrIpt:alert(1)">x</a>', 'url-scheme-not-allowed', 'href="javascrIpt:alert(1)"'],
            'iframe host'      => ['<IFRAME src="https://evil.example/"></IFRAME>', 'iframe-host', 'https://evil.example/'],
            'css at-rule'      => ['<style>@Import url(x.css);</style>', 'css-not-allowed', '@Import'],
            'css function'     => ['<p style="background:Image-set(url(x.png) 1x)">x</p>', 'css-not-allowed', 'Image-set('],
        ];
    }
}
