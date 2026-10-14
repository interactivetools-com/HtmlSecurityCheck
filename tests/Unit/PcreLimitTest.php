<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Unit;

use Itools\HtmlSecurityCheck\Tests\Support\HtmlSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Past a PCRE limit a regex returns false (or null), and every check reads that as a rejection,
 * never as "no match". No pattern here gets near a limit on real input, so each case lowers one
 * limit, which is read at match time, until only the regex under test returns false. The JIT
 * counts its limits differently and never stops on these inputs, so the test turns it off, in a
 * process of its own: a pattern another test compiled with the JIT keeps using it.
 *
 * The CSS check reports the block as css-not-allowed. Everywhere else the walk stops and reports
 * not-checked, since an undecoded or unread value could hide a scheme.
 *
 * No case for the url() escape scan: on every input where it returns false, the banned-list scan
 * before it returns false on the same text, and that refuses the block first. None either for the
 * control-character scan, the srcset split or the whitespace strip in URLs: they return false only
 * at a limit of 1, where the UTF-8 check before them refuses everything.
 *
 * Codes: css-not-allowed, not-checked.
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

    #[DataProvider('notCheckedProvider')]
    #[RunInSeparateProcess]
    public function testFalseStopsTheWalk(string $setting, string $limit, string $html): void
    {
        ini_set('pcre.jit', '0');
        ini_set($setting, $limit);
        try {
            $this->assertRejects($html, 'not-checked', $setting === 'pcre.backtrack_limit' ? 'Backtrack limit exhausted' : 'Recursion limit exhausted');
        } finally {
            ini_restore($setting);
            ini_restore('pcre.jit');
        }
    }

    public static function notCheckedProvider(): array
    {
        return [
            'url scheme'          => ['pcre.backtrack_limit', '2', '<a href="javascript:alert(1)">x</a>'],
            'url scheme, srcset'  => ['pcre.backtrack_limit', '2', '<img srcset="a.png 1x, javascript:alert(1) 2x">'],
            'character reference' => ['pcre.backtrack_limit', '3', '<a title="&amp;x">x</a>'],   // an undecoded href="&#x6a;avascript:x" has no scheme to find
        ];
    }
}
