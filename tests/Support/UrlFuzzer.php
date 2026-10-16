<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck\Tests\Support;

/**
 * Random URL attributes for the Chrome URL cross-check: known URLs, allowed and not, each edited
 * one to five times around the scheme, the colon, the slashes, the host, userinfo, the port and the
 * srcset and ping separators. An edit inserts, deletes, replaces or character-reference-encodes,
 * and what it inserts is written as raw bytes or as a reference (decimal, hex, zero-padded, named,
 * with or without the semicolon). The quoting and the case of the attribute name vary too.
 *
 *     $fragments = UrlFuzzer::fragments(seed: 1, samples: 50000);   // [id => html], the same for the same seed
 *
 * When the check accepts a fragment and Chrome resolves one of its URLs to a scheme or iframe host
 * the defaults do not allow, the check read that URL differently from a browser. A fragment that
 * once got through goes in SEEDS, so every run after that tries it and its near misses again.
 */
final class UrlFuzzer
{
    // element and attribute pairs: the URL attributes on the elements that use them, the iframe src most of all,
    // and two attributes the check reads only for javascript:
    private const TARGETS = [
        ['a', 'href'], ['a', 'href'], ['a', 'href'], ['area', 'href'], ['a', 'ping'], ['area', 'ping'],
        ['img', 'src'], ['img', 'src'], ['img', 'srcset'], ['img', 'srcset'], ['source', 'srcset'], ['source', 'src'],
        ['video', 'poster'], ['video', 'src'], ['audio', 'src'], ['track', 'src'],
        ['blockquote', 'cite'], ['q', 'cite'], ['del', 'cite'], ['ins', 'cite'], ['img', 'longdesc'],
        ['table', 'background'], ['td', 'background'],
        ['iframe', 'src'], ['iframe', 'src'], ['iframe', 'src'], ['iframe', 'src'], ['iframe', 'src'], ['iframe', 'src'],
        ['form', 'action'], ['button', 'formaction'], ['input', 'formaction'], ['input', 'src'],
        ['a', 'title'], ['input', 'value'],
    ];

    // URLs to start from: script and local schemes, allowed URLs, and iframe hosts that are almost a listed one
    private const SEEDS = [
        'javascript:alert(1)', 'JavaScript:alert(1)', 'javascript://%0aalert(1)', 'vbscript:msgbox(1)',
        'data:text/html,<script>alert(1)</script>', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        'data:image/svg+xml,<svg onload=alert(1)>', 'data:,x', 'blob:https://example.test/0b1c', 'filesystem:https://example.test/temporary/x',
        'file:///etc/passwd', 'ftp://evil.test/x', 'about:blank', 'view-source:https://x', 'intent://x#Intent;end', 'ms-msdt:x',
        'https://example.test/x', 'http://example.test/', '//cdn.example/x.png', '/page', 'page.html', '#top', '?q=1', '',
        'mailto:a@example.test', 'tel:+15551234', 'https://user:pass@example.test:8443/x?y#z', '/time/12:30', './a:b',
        'https://www.youtube.com/embed/x', '//www.youtube.com/embed/x', 'http://player.vimeo.com/video/1',
        'https://www.google.com/maps/embed?pb=1', 'https://www.youtube-nocookie.com/embed/x',
        'https://www.youtube.com.evil.test/', 'https://evil.test\@www.youtube.com/', 'https://www.youtube.com@evil.test/',
        '//evil.test/', 'https:evil.test', '/\evil.test/', '\\\\evil.test\x', 'https:www.youtube.com', 'https:/www.youtube.com/',
        'https:///www.youtube.com/', 'https://www.yоutube.com/', 'https://www.youtube.com./', 'https://WWW.YOUTUBE.COM/',
        'https://www.youtube.com:443/', 'https://www.youtube.com:/', 'https://www.youtube.com:8080/', 'https://127.0.0.1/',
        'https://0x7f.1/', 'https://2130706433/', 'https://[::1]/', 'https://[::ffff:127.0.0.1]/', 'https://www.youtube.com%2eevil.test/',
        'https://www%2eyoutube%2ecom/', 'https://xn--www-youtube.com/', 'https://www.youtube.com#@evil.test',
        'https://www.youtube.com?@evil.test', 'C|/x', 'https:C:/x', 'a.png 1x, javascript:alert(1) 2x', 'x.php?a=1,javascript:2 1x',
        'a.png 1x,b.png 2x', '/a /b', 'a.png (javascript:x), b.png', 'a.png 1x, data:text/html,x 2x',
    ];

    // pieces an edit inserts, around the scheme, the slashes, the host and the separators
    private const PIECES = [
        ':', '://', '//', '/', '\\', '\\\\', '@', '.', '..', ',', ' ', '?', '#', '[', ']', '%', '=', ';', '(', ')', '1x', '2x',
        'javascript', 'java', 'script', 'vbscript:', 'data:', 'data:text/html,', 'blob:', 'filesystem:', 'file:',
        'https:', 'http:', 'https://', 'mailto:', 'tel:', 'www.youtube.com', 'evil.test', '.evil.test', 'evil.test@',
        'user@', 'user:pass@', ':443', ':80', ':8080', ':', '127.0.0.1', '[::1]', 'xn--', 'C:', 'C|',
        '%3A', '%3a', '%2F', '%2f', '%40', '%5C', '%2e', '%2E', '%09', '%0a', '%0d', '%00', '%20', '%25',
    ];

    // single characters an edit inserts: ASCII controls, space and DEL, Unicode whitespace, invisible characters,
    // full-width and other lookalikes of : / @ \ . and the letters of javascript
    private const CHARACTERS = [
        "\t", "\n", "\r", ' ', "\x0B", "\x0C", "\x00", "\x01", "\x1F", "\x7F",
        "\u{A0}", "\u{85}", "\u{1680}", "\u{2000}", "\u{2007}", "\u{200A}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}",
        "\u{AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{180E}", "\u{34F}",
        "\u{FF1A}", "\u{FF0F}", "\u{FF20}", "\u{FF3C}", "\u{FF0E}", "\u{3002}", "\u{FF61}", "\u{2044}", "\u{2215}", "\u{A789}",
        "\u{FF4A}", "\u{FF41}", "\u{FF56}", "\u{FF53}", "\u{FF43}", "\u{FF52}", "\u{FF49}", "\u{FF50}", "\u{FF54}",
        "\u{130}", "\u{131}", "\u{17F}", "\u{212A}", "\u{FB06}", "\u{43E}", "\u{435}",
        'a', 'j', 's', 'w', '0', '-', '+', '"', "'", '<', '>', '&', '`',
    ];

    // named references for the characters that have one, with the legacy names a browser also reads without a semicolon
    private const NAMED = [
        ':'  => ['colon'], "\t" => ['Tab'], "\n" => ['NewLine'], '/' => ['sol'], '@' => ['commat'], '.' => ['period'],
        '\\' => ['bsol'], ',' => ['comma'], '?' => ['quest'], '#' => ['num'], '%' => ['percnt'], '(' => ['lpar'],
        ')'  => ['rpar'], '=' => ['equals'], ';' => ['semi'], '+' => ['plus'], '[' => ['lsqb', 'lbrack'], ']' => ['rsqb', 'rbrack'],
        '&'  => ['amp', 'AMP'], '"' => ['quot', 'QUOT'], "'" => ['apos'], '<' => ['lt', 'LT'], '>' => ['gt', 'GT'],
        "\u{A0}" => ['nbsp', 'NonBreakingSpace'], "\u{AD}" => ['shy'], "\u{200B}" => ['ZeroWidthSpace', 'NegativeThinSpace'],
        "\u{200C}" => ['zwnj'], "\u{200D}" => ['zwj'], "\u{2060}" => ['NoBreak'], "\u{205F}" => ['MediumSpace'],
        "\u{2009}" => ['thinsp', 'ThinSpace'], "\u{200A}" => ['hairsp', 'VeryThinSpace'], 'j' => ['jscr'],
    ];

    // references whose decoded value is not their code point, or which decode with no semicolon before a letter
    private const ODD_REFERENCES = [
        '&#0;', '&#x0;', '&#x80;', '&#x9F;', '&#xD800;', '&#x110000;', '&#13;', '&#x0D;', '&#11;', '&#12;', '&#x7F;',
        '&#58', '&#x3a', '&#0000000058;', '&#x00000003A;', '&colon', '&Tab', '&NewLine', '&nbsp', '&amp', '&ampcolon;',
        '&#106', '&#x6A', '&#X6a;', '&#9', '&#10', '&#32;',
    ];

    /**
     * Distinct fragments from the seed, keyed 0, 1, 2 and so on
     *
     * @return array<int, string>
     */
    public static function fragments(int $seed, int $samples): array
    {
        mt_srand($seed);
        $fragments = [];
        for ($i = 0; $i < $samples; $i++) {
            [$element, $attribute] = self::pick(self::TARGETS);
            $value       = self::mutate(self::pick(self::SEEDS));
            $attributes  = [self::attribute($attribute, $value)];
            $extra       = mt_rand(0, 9);
            if ($extra === 0) {
                array_unshift($attributes, self::attribute($attribute, self::pick(self::SEEDS)));   // a duplicate first: the browser keeps the first
            } elseif ($extra === 1) {
                $attributes[] = self::attribute($attribute, self::mutate(self::pick(self::SEEDS)));   // a duplicate after
            } elseif ($extra === 2) {
                $attributes[] = self::attribute(self::pick(['src', 'srcset', 'href', 'ping', 'poster']), self::mutate(self::pick(self::SEEDS)));
            }
            $separator   = self::pick([' ', ' ', ' ', '/', "\n", "\t", ' / ']);
            $tag         = self::randomCase($element, 8);
            $open        = "<$tag$separator" . implode(self::pick([' ', ' ', '/', "\n"]), $attributes) . self::pick(['>', '>', '>', ' >', '/>']);
            $fragment    = match ($element) {
                'img', 'source', 'track', 'input', 'area' => $open,
                'td'                                      => "<table><tr>{$open}x</td></tr></table>",
                default                                   => "{$open}x</$element>",
            };
            if (mb_check_encoding($fragment, 'UTF-8')) {
                $fragments[] = $fragment;
            }
        }
        mt_srand();   // back to a random sequence for whatever runs next
        return array_values(array_unique($fragments));
    }

    /**
     * A value edited one to five times, most edits near a scheme colon, slash, @, comma or space or in the first characters
     */
    private static function mutate(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($edits = mt_rand(1, 5); $edits > 0; $edits--) {
            $at = self::position($chars);
            switch (mt_rand(0, 9)) {
                case 0:   // delete
                    array_splice($chars, $at, 1);
                    break;
                case 1:   // replace with a character or piece
                case 2:
                    array_splice($chars, $at, 1, [self::encode(self::insertion())]);
                    break;
                case 3:   // character-reference-encode the character there
                case 4:
                    if (isset($chars[$at]) && mb_strlen($chars[$at]) === 1) {
                        $chars[$at] = self::reference($chars[$at]);
                    }
                    break;
                case 5:   // an odd reference
                    array_splice($chars, $at, 0, [self::pick(self::ODD_REFERENCES)]);
                    break;
                case 6:   // swap the case of the letters around there
                    for ($j = $at; $j < min(count($chars), $at + 4); $j++) {
                        $chars[$j] = mt_rand(0, 1) ? strtoupper($chars[$j]) : strtolower($chars[$j]);
                    }
                    break;
                default:   // insert a character or piece
                    array_splice($chars, $at, 0, [self::encode(self::insertion())]);
            }
        }
        return implode('', $chars);
    }

    /**
     * Where an edit goes: two times in three next to a : / \ @ , . or space or in the first twelve
     * characters, otherwise anywhere
     */
    private static function position(array $chars): int
    {
        $count = count($chars);
        if ($count === 0 || mt_rand(0, 2) === 0) {
            return mt_rand(0, $count);
        }
        $hot = [];
        foreach ($chars as $index => $char) {
            if (str_contains(':/\\@,. ?#', $char)) {
                $hot[] = $index;
                $hot[] = $index + 1;
            }
        }
        if (!$hot || mt_rand(0, 2) === 0) {
            return mt_rand(0, min($count, 12));
        }
        return self::pick($hot);
    }

    private static function insertion(): string
    {
        return mt_rand(0, 1) ? self::pick(self::CHARACTERS) : self::pick(self::PIECES);
    }

    /**
     * A piece written as raw bytes three times in five, otherwise with one of its characters, or every one, as a
     * reference. A control character the check refuses as a raw byte goes raw only one time in ten
     */
    private static function encode(string $piece): string
    {
        $raw = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $piece) ? mt_rand(0, 9) === 0 : mt_rand(0, 4) < 3;
        if ($raw) {
            return $piece;
        }
        $chars = preg_split('//u', $piece, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (mt_rand(0, 1)) {
            return implode('', array_map(self::reference(...), $chars));
        }
        $at         = mt_rand(0, count($chars) - 1);
        $chars[$at] = self::reference($chars[$at]);
        return implode('', $chars);
    }

    /**
     * One character as a random character reference: decimal, hex with x or X, zero-padded, or named, with or
     * without the semicolon
     */
    private static function reference(string $char): string
    {
        $code      = mb_ord($char, 'UTF-8');
        $semicolon = mt_rand(0, 3) ? ';' : '';
        $zeros     = mt_rand(0, 3) ? '' : str_repeat('0', mt_rand(1, 8));
        return match (isset(self::NAMED[$char]) ? mt_rand(0, 3) : mt_rand(0, 2)) {
            0       => "&#$zeros$code$semicolon",
            1       => '&#x' . $zeros . dechex($code) . $semicolon,
            2       => '&#X' . $zeros . strtoupper(dechex($code)) . $semicolon,
            default => '&' . self::pick(self::NAMED[$char]) . $semicolon,
        };
    }

    /**
     * name=value with random quoting, spacing around = and name case
     */
    private static function attribute(string $name, string $value): string
    {
        $name   = self::randomCase($name, 6);
        $equals = self::pick(['=', '=', '=', ' = ', "\n=", "=\t"]);
        return match (mt_rand(0, 5)) {
            0       => "$name$equals'$value'",
            1       => "$name$equals$value",
            default => "$name$equals\"$value\"",
        };
    }

    /**
     * The name as written, or one time in $oneIn with random letter case
     */
    private static function randomCase(string $name, int $oneIn): string
    {
        if (mt_rand(1, $oneIn) !== 1) {
            return $name;
        }
        return implode('', array_map(fn(string $c) => mt_rand(0, 1) ? strtoupper($c) : $c, str_split($name)));
    }

    /**
     * A random entry, from mt_rand, whose seeded sequence PHP keeps the same across versions
     */
    private static function pick(array $list): mixed
    {
        return $list[array_keys($list)[mt_rand(0, count($list) - 1)]];
    }
}
