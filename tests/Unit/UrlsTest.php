<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * URL schemes in attribute values: the allowed schemes on URL attributes, javascript:
 * refused in every attribute, and the ways browsers let a scheme hide (entities, whitespace,
 * control characters, case).
 *
 * Code: url-scheme-not-allowed.
 */
final class UrlsTest extends HtmlSecurityCheckTestCase
{
    //region Allowed

    #[DataProvider('allowedUrlProvider')]
    public function testAllowedUrl(string $value): void
    {
        $this->assertAccepts("<a href=\"$value\">x</a>");
        $this->assertAccepts("<img src=\"$value\">");
    }

    public static function allowedUrlProvider(): array
    {
        return [
            'https'             => ['https://example.com/a?b=1#c'],
            'http'              => ['http://example.com/'],
            'mailto'            => ['mailto:a@example.com?subject=Hi'],
            'tel'               => ['tel:+1-555-0100'],
            'uppercase scheme'  => ['HTTPS://EXAMPLE.COM/'],
            'relative path'     => ['about/team.html'],
            'root path'         => ['/about/'],
            'protocol relative' => ['//cdn.example.com/x.png'],
            'fragment'          => ['#section-2'],
            'query'             => ['?page=2'],
            'empty'             => [''],
            'colon in the path' => ['/time/12:30'],
            'colon in query'    => ['?when=12:30'],
            'spaces'            => ['my file.pdf'],
            'backslash in path' => ['images\\logo.png'],
        ];
    }

    public function testUrlListsPass(): void
    {
        $this->assertAccepts('<img src="a.jpg" srcset="a-480.jpg 480w, https://cdn.example.com/a-800.jpg 800w,a-2x.jpg 2x" sizes="(max-width: 600px) 480px, 800px">');
        $this->assertAccepts('<img srcset="/time/12:30.jpg 1x, ?when=12:30 1.5x">');
        $this->assertAccepts('<a href="/x" ping="/log https://stats.example.com/ping">x</a>');
    }

    #[DataProvider('imageDataProvider')]
    public function testImageDataPassesOnImgSrc(string $value): void
    {
        $this->assertAccepts("<img src=\"$value\">");
    }

    public static function imageDataProvider(): array
    {
        return [
            'png'              => ['data:image/png;base64,iVBORw0KGgo='],
            'svg'              => ['data:image/svg+xml,<svg onload=alert(1)></svg>'],   // an img shows an SVG as a picture: no script, no loads
            'uppercase'        => ['DATA:IMAGE/PNG;BASE64,iVBORw0KGgo='],
            'leading newline'  => ["\n data:image/gif;base64,R0lGODlh"],
            'no base64'        => ['data:image/svg+xml;charset=utf-8,%3Csvg%2F%3E'],
        ];
    }

    #[DataProvider('urlAttributeProvider')]
    public function testEveryUrlAttributeIsChecked(string $attribute): void
    {
        $this->assertAccepts("<a $attribute=\"https://example.com/\">x</a>");
        $this->assertRejects("<a $attribute=\"javascript:alert(1)\">x</a>", 'url-scheme-not-allowed', "$attribute=\"javascript:alert(1)\"");
        $this->assertRejects("<a $attribute=\"ftp://example.com/\">x</a>", 'url-scheme-not-allowed', "$attribute=\"ftp://example.com/\"");
    }

    public static function urlAttributeProvider(): array
    {
        $cases = [];
        foreach (HtmlSecurityCheck::rules()['urlAttributes'] as $attribute) {
            if ($attribute !== 'formaction') {   // refused outright while forms are off; AttributesTest covers it with forms on
                $cases[$attribute] = [$attribute];
            }
        }
        return $cases;
    }

    //endregion
    //region Refused

    #[DataProvider('refusedUrlProvider')]
    public function testRefusedUrl(string $html): void
    {
        $this->assertRejects($html, 'url-scheme-not-allowed');
    }

    public static function refusedUrlProvider(): array
    {
        return [
            'javascript'                 => ['<a href="javascript:alert(1)">x</a>'],
            'uppercase'                  => ['<a href="JAVASCRIPT:alert(1)">x</a>'],
            'mixed case'                 => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'vbscript'                   => ['<a href="vbscript:MsgBox(1)">x</a>'],
            'data'                       => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            'data image on href'         => ['<a href="data:image/png;base64,iVBORw0KGgo=">x</a>'],
            'data image on srcset'       => ['<img srcset="data:image/png;base64,iVBORw0KGgo= 1x">'],
            'data image on poster'       => ['<video poster="data:image/png;base64,iVBORw0KGgo="></video>'],
            'data page on img'           => ['<img src="data:text/html,<script>alert(1)</script>">'],
            'data image with no slash'   => ['<img src="data:imagepng;base64,iVBORw0KGgo=">'],
            'unknown scheme on href'     => ['<a href="ftp://example.com/">x</a>'],
            'file scheme'                => ['<a href="file:///etc/passwd">x</a>'],
            'network path'               => ['<img src="\\\\evil.example\\share\\x.png">'],
            'network path on href'       => ['<a href="\\\\evil.example\\x">x</a>'],
            'network path, slash first'  => ['<img src="/\\evil.example/x.png">'],
            'network path, slash second' => ['<img src="\\/evil.example/x.png">'],
            'network path after a tab'   => ['<img src="&#9;\\\\evil.example\\x.png">'],
            'network path in entities'   => ['<img src="&#92;&#92;evil.example&#92;x.png">'],
            'network path after https'   => ['<a href="https:\\\\evil.example\\x">x</a>'],
            'network path in srcset'     => ['<img srcset="a.png 1x, \\\\evil.example\\x.png 2x">'],
            'network path on word image' => ['<v:imagedata src="\\\\evil.example\\x.png"/>'],
            'drive letter and bar'       => ['<img src="C|/x.png">'],
            'drive letter after https'   => ['<a href="https:C:/x">x</a>'],
            'leading spaces'             => ['<a href="   javascript:alert(1)">x</a>'],
            'leading tab and newline'    => ["<a href=\"\t\njavascript:alert(1)\">x</a>"],
            'newline inside the scheme'  => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'tab inside the scheme'      => ["<a href=\"jav\tascript:alert(1)\">x</a>"],
            'named entity for colon'     => ['<a href="javascript&colon;alert(1)">x</a>'],
            'named entity for tab'       => ['<a href="jav&Tab;ascript:alert(1)">x</a>'],
            'named entity for newline'   => ['<a href="jav&NewLine;ascript:alert(1)">x</a>'],
            'decimal entities'           => ['<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>'],
            'hex entities'               => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'entity without semicolon'   => ['<a href="&#106avascript:alert(1)">x</a>'],
            'unquoted value'             => ['<a href=javascript:alert(1)>x</a>'],
            'single quoted value'        => ["<a href='javascript:alert(1)'>x</a>"],
            'src on img'                 => ['<img src="javascript:alert(1)">'],
            'poster on video'            => ['<video poster="javascript:alert(1)"></video>'],
            'background on table'        => ['<table background="javascript:alert(1)"></table>'],
            'ping'                       => ['<a href="/x" ping="javascript:alert(1)">x</a>'],
            'srcset'                     => ['<img srcset="javascript:alert(1) 1x">'],
            'srcset second url'          => ['<img srcset="a.png 1x, javascript:alert(1) 2x">'],
            'srcset with no spaces'      => ['<img srcset="a.png 1x,javascript:alert(1) 2x">'],
            'srcset with no descriptor'  => ['<img srcset="a.png,javascript:alert(1)">'],
            'ping second url'            => ['<a href="/x" ping="/log javascript:alert(1)">x</a>'],
            'word element'               => ['<v:roundrect href="javascript:alert(1)">x</v:roundrect>'],
            'plain attribute'            => ['<p title="javascript:alert(1)">x</p>'],
            'value attribute'            => ['<li value="javascript:alert(1)">'],   // a script that copies a value into a link runs it
            'prefixed attribute'         => ['<p aria-describedby="javascript:alert(1)">x</p>'],
            'word attribute'             => ['<v:shape o:href="javascript:alert(1)">x</v:shape>'],
        ];
    }

    public function testOtherAttributesOnlyRefuseJavascript(): void
    {
        $this->assertAccepts('<p title="Note: see below" alt="Warning: hot" aria-label="noon:sharp" content="width=device-width">x</p>');
        $this->assertAccepts('<a href="/x" title="ftp://example.com/">x</a>');
        $this->assertAccepts('<p title="vbscript:x" aria-description="data:image/png;base64,iVBORw0KGgo=">x</p>');   // no current browser runs either as the page
        $this->assertAccepts('<p data="Note: x" xmlns:o="urn:schemas-microsoft-com:office:office">x</p>');   // URLs only on <object> and <svg>, both refused
        $this->assertRejects('<p aria-label="javascript:alert(1)">x</p>', 'url-scheme-not-allowed', 'aria-label="javascript:alert(1)"');
    }

    public function testDetailQuotesTheAttributeAsDecoded(): void
    {
        $violation = $this->assertRejects('<a href="javascript&colon;alert(1)">x</a>', 'url-scheme-not-allowed', 'href="javascript:alert(1)"');
        $this->assertSame('href="javascript:alert(1)" uses a URL scheme that is not allowed', $violation->message);
    }

    //endregion
    //region The $urlSchemes Setting

    public function testASiteCanChangeTheSchemeList(): void
    {
        $this->withSettings(['urlSchemes' => ['https', 'SMS']], function () {
            foreach ([true, false] as $fastPath) {
                $this->withSettings(['fastPath' => $fastPath], function () {
                    $this->assertAccepts('<a href="sms:+16045551234?body=Hi">x</a>');
                    $this->assertAccepts('<a href="SMS:+16045551234">x</a>');   // the list and the value both read lowercase
                    $this->assertRejects('<a href="mailto:x@example.com">x</a>', 'url-scheme-not-allowed', 'href="mailto:x@example.com"');
                });
            }
            $this->assertSame(['https', 'sms'], HtmlSecurityCheck::rules()['urlSchemes']);
        });
    }

    public function testJavascriptStaysRefusedWhateverTheListSays(): void
    {
        $this->withSettings(['urlSchemes' => ['https', 'javascript', 'JavaScript']], function () {
            foreach ([true, false] as $fastPath) {
                $this->withSettings(['fastPath' => $fastPath], fn() => $this->assertRejects('<a href="javascript:alert(1)">x</a>', 'url-scheme-not-allowed'));
            }
            $this->assertSame(['https'], HtmlSecurityCheck::rules()['urlSchemes']);
        });
    }

    /**
     * An entry that is not a scheme name is ignored. The fast path matched ' javascript' as written, so
     * href=" javascript:..." skipped the full check, and the browser drops the space and runs it
     */
    public function testAnEntryThatIsNotASchemeNameIsIgnored(): void
    {
        $this->withSettings(['urlSchemes' => ['https', ' javascript', "java\tscript", 'javascript:', "javascript\n", 'tel:']], function () {
            foreach ([true, false] as $fastPath) {
                $this->withSettings(['fastPath' => $fastPath], function () {
                    $this->assertRejects('<a href=" javascript:alert(1)">x</a>', 'url-scheme-not-allowed');
                    $this->assertRejects("<a href=\"java\tscript:alert(1)\">x</a>", 'url-scheme-not-allowed');
                });
            }
            $this->assertSame(['https'], HtmlSecurityCheck::rules()['urlSchemes']);
        });
    }

    public function testASchemeNameIsMatchedAsWritten(): void
    {
        // a . or + in a listed name is that character, not a regex wildcard, on the fast path too
        $this->withSettings(['urlSchemes' => ['web+coffee', 'a.b']], function () {
            $this->assertAccepts('<a href="web+coffee:brew">x</a>');
            $this->assertAccepts('<a href="a.b:x">x</a>');
            $this->assertRejects('<a href="axb:x">x</a>', 'url-scheme-not-allowed');
        });
    }

    //endregion
}
