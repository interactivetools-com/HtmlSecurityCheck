<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use Itools\HtmlSecurityCheck\Result;
use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use Itools\HtmlSecurityCheck\Violation;

/**
 * The shape of what check() returns: ok, document order, one violation per distinct problem,
 * the error cap, the detail length, and what rules() reports.
 */
final class ResultTest extends HtmlSecurityCheckTestCase
{
    public function testOkResult(): void
    {
        $result = HtmlSecurityCheck::check('<p>Hello</p>');
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function testErrorsInDocumentOrder(): void
    {
        $result = HtmlSecurityCheck::check('<p onclick="a">x</p><script></script><a href="javascript:b">y</a>');
        $this->assertFalse($result->ok);
        $this->assertSame(
            ['event-handler: onclick', 'element-not-allowed: <script>', 'url-scheme-not-allowed: href="javascript:b"'],
            array_map(fn(Violation $v) => "$v->code: $v->detail", $result->errors),
        );
    }

    public function testSameProblemReportsOnce(): void
    {
        $result = HtmlSecurityCheck::check('<p onclick="a">x</p><p onclick="b">y</p><script></script><script></script>');
        $this->assertSame(['event-handler: onclick', 'element-not-allowed: <script>'], array_map(fn(Violation $v) => "$v->code: $v->detail", $result->errors));
    }

    public function testOneTagCanBreakSeveralRules(): void
    {
        $result = HtmlSecurityCheck::check('<a href="javascript:x" onclick="y" style="width: expression(1)" srcdoc="z">a</a>');
        $this->assertSame(
            ['url-scheme-not-allowed', 'event-handler', 'css-not-allowed', 'attribute-not-allowed'],
            array_map(fn(Violation $v) => $v->code, $result->errors),
        );
    }

    public function testViolationFields(): void
    {
        $violation = HtmlSecurityCheck::check('<script src="x">')->errors[0];
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('<script src="x">', $violation->detail);
        $this->assertSame('%s is not allowed', $violation->template);
        $this->assertSame('<script src="x"> is not allowed', $violation->message);
        $this->assertSame($violation->message, sprintf($violation->template, $violation->detail));
    }

    public function testEveryTemplateHasOnePlaceholder(): void
    {
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertSame(1, substr_count($template, '%s'), "template for $code");
            $this->assertStringNotContainsString('%%', $template, "template for $code");
        }
    }

    public function testLimits(): void
    {
        $this->withSettings(['maxErrors' => 3, 'maxDetailLength' => 12], function () {
            $html = '';
            foreach (range(1, 5) as $n) {
                $html .= "<badelement$n attr=\"x\"></badelement$n>";   // the number is inside the cut, so the details stay distinct
            }
            $result = HtmlSecurityCheck::check($html);
            $this->assertCount(3, $result->errors);
            $this->assertSame('<badelement1...', $result->errors[0]->detail);
        });
    }

    /** A limit below 1 reports one error, so refused content never comes back as passing */
    public function testMaxErrorsBelowOneCountsAsOne(): void
    {
        foreach ([0, -1] as $maxErrors) {
            $this->withSettings(['maxErrors' => $maxErrors], function () use ($maxErrors) {
                foreach (['<script>alert(1)</script>', "caf\xE9", '<!--[if IE]><script>a</script><![endif]-->'] as $html) {
                    $this->assertCount(1, HtmlSecurityCheck::check($html)->errors, "maxErrors $maxErrors: $html");
                }
            });
        }
    }

    /** Names and CSS tokens are cut too, so a 500-character attribute name gives a readable message */
    public function testEveryDetailIsCut(): void
    {
        $long = str_repeat('a', 500);
        $cut  = fn(string $prefix) => substr($prefix . $long, 0, 80) . '...';
        $this->assertRejects("<p on$long=\"x\">x</p>", 'event-handler', $cut('on'));
        $this->assertRejects("<p x$long=\"x\">x</p>", 'attribute-not-allowed', $cut('x'));
        $this->assertRejects("<style>[x$long^=a]{color:red}</style>", 'css-not-allowed', $cut('[x'));
    }

    public function testDetailIsCutOnACharacterBoundary(): void
    {
        $this->withSettings(['maxDetailLength' => 9], function () {
            $violation = HtmlSecurityCheck::check('<script>日本語</script>')->errors[0];   // <script> is exactly 8 characters
            $this->assertSame('<script>', $violation->detail);
            $violation = HtmlSecurityCheck::check('<script 日本語="x">')->errors[0];
            $this->assertSame('<script 日...', $violation->detail);
        });
    }

    public function testDetailIsOneLine(): void
    {
        $violation = HtmlSecurityCheck::check("<script\nsrc=\"x\">")->errors[0];
        $this->assertSame('<script\nsrc="x">', $violation->detail);
        $violation = HtmlSecurityCheck::check("<script\r\nsrc=\"x\">")->errors[0];
        $this->assertSame('<script\nsrc="x">', $violation->detail);   // CRLF is LF to the tokenizer, and the detail follows it
    }

    public function testRules(): void
    {
        $rules = HtmlSecurityCheck::rules();
        $this->assertSame(
            ['elements', 'formElements', 'attributes', 'attributePrefixes', 'officeNamespaces', 'formAttributes', 'urlSchemes', 'scriptSchemes', 'cssUrlSchemes', 'cssFunctions', 'cssAtRules', 'urlAttributes', 'allowForms', 'allowStyleAttribute', 'allowStyleElement', 'allowEmbeds', 'iframeHosts'],
            array_keys($rules),
        );
        $this->assertContains('p', $rules['elements']);
        $this->assertNotContains('script', $rules['elements']);
        $this->assertContains('class', $rules['attributes']);
        $this->assertNotContains('srcdoc', $rules['attributes']);
        $this->assertFalse($rules['allowForms']);
        $this->withSettings(['allowForms' => true], fn() => $this->assertTrue(HtmlSecurityCheck::rules()['allowForms']));
    }

    public function testEmptyInput(): void
    {
        $this->assertAccepts('');
        $this->assertAccepts('   ');
    }
}
