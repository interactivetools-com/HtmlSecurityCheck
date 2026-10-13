<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Integration;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the doc examples true. The PHP examples cannot be run from the page, so their
 * output comments are mirrored by hand below; editing one of those examples means editing
 * its mirror. The switch defaults are read from the pages instead, since they are one line
 * of PHP the test can compare with the code.
 */
final class DocsExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** README quick start and the AI reference: the message for an onclick attribute */
    public function testEventHandlerMessage(): void
    {
        $result = HtmlSecurityCheck::check('<p onclick="x()">Hi</p>');
        $this->assertSame('onclick= event handler attributes are not allowed', $result->errors[0]->message);
    }

    /** README, the rest of the API, and the AI reference Violation section: the four fields */
    public function testViolationFields(): void
    {
        $violation = HtmlSecurityCheck::check('<p onclick="x()">Hi</p>')->errors[0];
        $this->assertSame('event-handler', $violation->code);
        $this->assertSame('onclick', $violation->detail);
        $this->assertSame('%s= event handler attributes are not allowed', $violation->template);
        $this->assertSame('onclick= event handler attributes are not allowed', $violation->message);
    }

    /** AI reference, Rules: Attributes: duplicate attributes, the first wins */
    public function testFirstDuplicateAttributeWins(): void
    {
        $this->assertTrue(HtmlSecurityCheck::check('<a href="https://x" href="javascript:y">')->ok);
        $this->assertFalse(HtmlSecurityCheck::check('<a href="javascript:y" href="https://x">')->ok);
    }

    /** AI reference, Rules: Elements: a refused element is one error with its attributes skipped */
    public function testRefusedElementIsOneError(): void
    {
        $result = HtmlSecurityCheck::check('<script onload="x" src="javascript:1"></script>');
        $this->assertCount(1, $result->errors);
        $this->assertSame('<script onload="x" src="javascript:1"> is not allowed', $result->errors[0]->message);
    }

    /** AI reference, Rules: URLs: the detail is the name and the decoded value; docs/errors.md says &colon; shows as : */
    public function testUrlDetailIsDecoded(): void
    {
        $violation = HtmlSecurityCheck::check('<a href="javascript&colon;alert(1)">x</a>')->errors[0];
        $this->assertSame('href="javascript:alert(1)"', $violation->detail);
    }

    /** README and the AI reference both print the switch defaults as PHP; those lines must match the code */
    public function testSwitchDefaultsMatchThePages(): void
    {
        $expected = [
            'allowForms'  => HtmlSecurityCheck::$allowForms,
            'allowStyles' => HtmlSecurityCheck::$allowStyles,
            'allowEmbeds' => HtmlSecurityCheck::$allowEmbeds,
            'iframeHosts' => HtmlSecurityCheck::$iframeHosts,
        ];
        foreach (['README.md', 'docs/ai-reference.md'] as $page) {
            $markdown = file_get_contents(self::ROOT . "/$page");
            foreach ($expected as $name => $value) {
                // HtmlSecurityCheck::$allowForms   = false;   // ...
                $this->assertMatchesRegularExpression('/^HtmlSecurityCheck::\$' . $name . ' += (.+?);/m', $markdown, "$page does not show the $name default");
                preg_match('/^HtmlSecurityCheck::\$' . $name . ' += (.+?);/m', $markdown, $match);
                $this->assertSame($value, eval("return $match[1];"), "$page shows a different $name default from the code");
            }
        }
    }
}
