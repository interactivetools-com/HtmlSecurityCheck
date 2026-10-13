<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Integration;

use Itools\HtmlSecurityCheck\HtmlSecurityCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Itools\HtmlSecurityCheck\Tools\RulesDoc\render;

require_once __DIR__ . '/../../tools/rules-doc.php';   // loaded here, not in setUpBeforeClass(), because the data provider runs first

/**
 * The rule blocks in docs/ai-reference.md are generated from HtmlSecurityCheck::rules() by
 * tools/rules-doc.php. This test regenerates them and fails when the page on disk differs,
 * so a change to a list or a default in src/ cannot ship without the docs.
 */
final class RulesDocTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return iterable<string, string[]> */
    public static function pageProvider(): iterable
    {
        foreach (\Itools\HtmlSecurityCheck\Tools\RulesDoc\PAGES as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('pageProvider')]
    public function testGeneratedBlocksAreCurrent(string $page): void
    {
        $current = file_get_contents(self::ROOT . "/$page");
        $this->assertSame(render($current), $current, "$page is out of date: run php tools/rules-doc.php");
    }

    #[DataProvider('pageProvider')]
    public function testEveryRuleHasABlock(string $page): void
    {
        $markdown = file_get_contents(self::ROOT . "/$page");
        foreach (array_keys(HtmlSecurityCheck::rules()) as $name) {
            $this->assertStringContainsString("<!-- rules:$name -->", $markdown, "$page has no block for rules()['$name']");
        }
    }
}
