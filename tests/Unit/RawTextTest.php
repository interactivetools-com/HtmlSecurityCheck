<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A < inside text a browser never reads as markup: the content of style, iframe and textarea, and a
 * comment that is not <!-- -->. Text to a browser, but a tag to strip_tags() with an allow list or to
 * an HTML4-era DOM, which is what re-parses stored content downstream.
 *
 * Code: less-than-in-text.
 */
final class RawTextTest extends HtmlSecurityCheckTestCase
{
    #[DataProvider('refusedProvider')]
    public function testALessThanIsRefused(string $html, string $detail): void
    {
        $this->withSettings(['allowForms' => true], function () use ($html, $detail) {
            $violation = $this->assertRejects($html, 'less-than-in-text', $detail);
            $this->assertSame("A < where a browser reads text, not tags: $detail", $violation->message);
        });
    }

    public static function refusedProvider(): array
    {
        $img = '<img src=x onerror=alert(1)>';
        return [
            'style'                   => ["<style>p{color:red}$img</style>", $img],
            'iframe'                  => ["<iframe src=\"https://www.youtube.com/embed/x\">$img</iframe>", $img],
            'textarea'                => ["<textarea>$img</textarea>", $img],
            'textarea, a lone <'      => ['<textarea>a < b</textarea>', '< b'],
            'detail runs to the end'  => ['<textarea>a <b> c</textarea>', '<b> c'],
            'bogus comment <?'        => ["<? $img", $img],
            'markup declaration <!x'  => ["<!x $img", $img],
            'end tag with a space'    => ["</ x $img", $img],
            'cdata'                   => ['<![CDATA[<b>]]>', '<b>'],
            // a parser on the pre-2025 spec (Chrome before 135) drops a style or iframe start tag inside select and reads on as markup
            'style inside a select'   => ["<select><style>$img</style></select>", $img],
            'iframe inside a select'  => ["<select><iframe src=\"https://www.youtube.com/embed/x\">$img</iframe></select>", $img],
            'tag after <!-- in style' => ["<style><!-- p{color:red} $img --></style>", "$img -->"],
            '<!-- in a textarea'      => ['<textarea><!-- x --></textarea>', '<!-- x -->'],   // only style gets the exception
            '<!x in style'            => ['<style><!x></style>', '<!x>'],
            // strip_tags() would end the comment inside the alt value and keep the second img as a tag
            '<!-- closed outside'     => ['<style><!--</style><img alt="--><img src=x onerror=alert(1)>">', '<!--'],
            '<!-- never closed'       => ['<style>p{} <!--</style>', '<!--'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testTextWithoutALessThanPasses(string $html): void
    {
        $this->withSettings(['allowForms' => true], fn() => $this->assertAccepts($html));
    }

    public static function acceptedProvider(): array
    {
        return [
            'encoded in a textarea'    => ['<textarea>&lt;b&gt; and &#60;</textarea>'],
            'child selector in css'    => ['<style>ul > li {color: red}</style>'],
            'word comment in style'    => ["<style><!--\n/* Font Definitions */\np.MsoNormal {margin: 0}\n--></style><p class=\"MsoNormal\">x</p>"],
            'two comment openers'      => ['<style><!-- a{} --> <!-- b{} --></style>'],
            'empty <!--> in style'     => ['<style><!--> p{}</style>'],
            'iframe fallback text'     => ['<iframe src="https://www.youtube.com/embed/x">Your browser has no frames</iframe>'],
            'word namespace comment'   => ['<?xml:namespace prefix = o ns = "urn:schemas-microsoft-com:office:office" /><p>x</p>'],
            'php block'                => ['<?php echo 1; ?>'],
            'real comment holds a tag' => ['<!-- <img src=x onerror=alert(1)> -->'],
            'conditional comment'      => ['<!--[if IE]><p>x</p><![endif]-->'],
            'lone < in text'           => ['<p>a < b</p>'],
        ];
    }

    public function testStyleContentIsStillCheckedAsCss(): void
    {
        $result = HtmlSecurityCheck::check('<style>p{width: expression(1)} <b></style>');
        $this->assertSame(['less-than-in-text', 'css-not-allowed'], array_column($result->errors, 'code'));
    }

    /**
     * One search for --> serves every <style> after it, so a page of <style><!--</style> with no
     * --> anywhere stays linear (each block searched to the end of the page took 1.2 s at 255 KB),
     * and a --> found past one block still closes the <!-- in the next
     */
    public function testStyleCommentsStayLinear(): void
    {
        $this->assertRejects(str_repeat('<style><!--</style>', 55000), 'less-than-in-text', '<!--');
        $this->assertAccepts('<style><!-- a{} --></style><style><!-- b{} --></style>');
        $this->assertRejects('<style><!--</style><style><!-- --></style>', 'less-than-in-text', '<!--');
    }

    /** The element is the one error: its content goes with it */
    public function testARefusedElementReportsOnce(): void
    {
        $this->assertSame(['element-not-allowed'], array_column(HtmlSecurityCheck::check('<textarea><b></textarea>')->errors, 'code'));   // forms are off by default
        $this->withSettings(['allowStyleElement' => false], function () {
            $this->assertSame(['element-not-allowed'], array_column(HtmlSecurityCheck::check('<style>p{width: expression(1)} <b></style>')->errors, 'code'));
        });
    }
}
