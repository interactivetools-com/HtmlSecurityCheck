<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Past a PCRE limit a regex returns false, and the CSS check reads that false as a rejection,
 * never as "no match". No CSS pattern gets near a limit on real input, so each case lowers one
 * limit, which is read at match time, until only the regex under test returns false. The JIT
 * counts its limits differently and never stops on these inputs, so the test turns it off, in a
 * process of its own: a pattern another test compiled with the JIT keeps using it.
 *
 * The url() escape scan has no case: on every input where it returns false, the banned-list scan
 * before it returns false on the same text, and that refuses the block first.
 *
 * Codes: css-not-allowed.
 */
class PcreLimitTest extends HtmlSecurityCheckTestCase
{
    #[DataProvider('guardProvider')]
    #[RunInSeparateProcess]
    public function testFalseRejects(string $setting, string $limit, string $css): void
    {
        ini_set('pcre.jit', '0');
        ini_set($setting, $limit);
        try {
            $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $css);   // the detail is the block, not a name or url()
        } finally {
            ini_restore($setting);
            ini_restore('pcre.jit');
        }
    }

    public static function guardProvider(): array
    {
        return [
            'function names' => ['pcre.backtrack_limit', '2', 'width: ' . str_repeat('a', 50) . '(1)'],
            'at-rule names'  => ['pcre.backtrack_limit', '2', '@media x'],
            'url() targets'  => ['pcre.recursion_limit', '3', "background: url('aaaaaaaa')"],
        ];
    }
}
