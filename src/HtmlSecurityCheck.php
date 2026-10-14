<?php
declare(strict_types=1);

namespace Itools\HtmlSecurityCheck;

use RuntimeException;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function addcslashes, array_diff, array_intersect, array_map, array_values, count, implode, in_array, preg_last_error_msg, preg_match, preg_match_all, preg_quote, preg_replace, preg_split, str_replace, str_starts_with, stripos, strlen, strncasecmp, strpos, strtolower, substr, trim;

use const PREG_OFFSET_CAPTURE, PREG_SPLIT_NO_EMPTY;

/**
 * Checks rich-text HTML for anything that could run script in a browser and reports every
 * problem it finds. The content is never modified: what passes is what gets stored.
 *
 *     $result = HtmlSecurityCheck::check($html);
 *     if (!$result->ok) {
 *         foreach ($result->errors as $violation) {
 *             echo htmlspecialchars($violation->message), "<br>";
 *         }
 *     }
 *
 *     HtmlSecurityCheck::$allowForms = true;            // switches are class properties, set once at startup
 *     HtmlSecurityCheck::$iframeHosts[] = 'example.com';
 *     $rules = HtmlSecurityCheck::rules();              // the tables and current switches, for docs and settings pages
 *
 * Elements and attributes are allowlists. Elements: the HTML Standard's element index minus
 * everything that runs script, loads a plugin, belongs in <head>, or changes how a browser
 * reads the bytes after it, plus Word's o:, v: and w: names. Attributes: the HTML Standard's
 * attribute index minus srcdoc and is, the old presentational ones, aria-*, and Word's own.
 * on* is refused by prefix, URL attributes may only use a scheme in $urlSchemes (http, https,
 * mailto and tel by default) or data:image/ on <img src>, and no other attribute but style may
 * start with javascript:. style is checked as CSS: function names and at-rules are allowlists
 * too, url() is limited to http and https, and a few patterns are refused on their own.
 * Unknown elements and attributes are refused.
 *
 * The check runs on the HTML5 token stream and never builds a tree, so it sees the same
 * tags and attributes a browser does, in one pass, with memory that does not grow with
 * nesting. Text and tags the rules can never refuse are stepped over in one regex match
 * instead of one token at a time ($fastPath); everything else is tokenized and checked.
 */
final class HtmlSecurityCheck
{
    //region Rules

    /**
     * Elements that pass. The HTML Standard's element index, minus script and plugin elements
     * (script, noscript, template, svg, math, object, embed, applet), page-level elements
     * (html, head, body, title, meta, base, link, frameset, frame), and the elements whose content
     * is text to a browser and has no use in content (plaintext, xmp, noembed, noframes).
     * Includes the obsolete presentational elements old content still holds (font, center,
     * strike, big, tt, marquee and friends), since browsers render them without script.
     * Also xml, the settings block Word puts on the clipboard: an unknown element to a browser,
     * whose content is checked like the rest. Old IE ran one only through datasrc, datafld and
     * dataformatas, which are not on the attribute list.
     * style, iframe and the form elements are not here: they pass by their switch ($allowStyleElement,
     * $allowEmbeds, and $allowForms with FORM_ELEMENTS).
     */
    public const ELEMENTS = [
        'a', 'abbr', 'acronym', 'address', 'area', 'article', 'aside', 'audio',
        'b', 'bdi', 'bdo', 'big', 'blink', 'blockquote', 'br',
        'canvas', 'caption', 'center', 'cite', 'code', 'col', 'colgroup',
        'data', 'dd', 'del', 'details', 'dfn', 'dialog', 'dir', 'div', 'dl', 'dt',
        'em', 'figcaption', 'figure', 'font', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'i', 'img', 'ins', 'kbd', 'li', 'listing', 'main', 'map', 'mark', 'marquee', 'menu', 'meter', 'multicol',
        'nav', 'nobr', 'ol', 'p', 'param', 'picture', 'pre', 'progress', 'q',
        'rb', 'rp', 'rt', 'rtc', 'ruby', 's', 'samp', 'search', 'section', 'slot', 'small', 'source', 'spacer', 'span', 'strike', 'strong', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'track', 'tt',
        'u', 'ul', 'var', 'video', 'wbr', 'xml',
    ];

    /**
     * Pass only when $allowForms is set. Forms submit data and can imitate a login box, but they run no script.
     */
    public const FORM_ELEMENTS = ['form', 'input', 'button', 'select', 'selectedcontent', 'option', 'optgroup', 'datalist', 'textarea', 'label', 'fieldset', 'legend', 'output'];

    /**
     * Attribute names that pass, on any element. Every other name is refused, including the ones a page's own
     * JavaScript reads as code (Alpine x-init, HTMX hx-get, Vue @click, Stimulus data-action). A listed name
     * can still be refused: style when $allowStyleAttribute is off. Every value still gets the URL and CSS checks.
     */
    public const ATTRIBUTES = [
        // The HTML Standard's attribute index, minus srcdoc (a whole document with script allowed), formaction
        // (FORM_ATTRIBUTES) and is (runs a page script's custom element code on the tag)
        'abbr', 'accept', 'accept-charset', 'accesskey', 'action', 'allow', 'allowfullscreen', 'alpha', 'alt', 'as', 'async',
        'autocapitalize', 'autocomplete', 'autocorrect', 'autofocus', 'autoplay',
        'blocking', 'charset', 'checked', 'cite', 'class', 'closedby', 'color', 'colorspace', 'cols', 'colspan', 'command',
        'commandfor', 'content', 'contenteditable', 'controls', 'coords', 'crossorigin',
        'data', 'datetime', 'decoding', 'default', 'defer', 'dir', 'dirname', 'disabled', 'download', 'draggable',
        'enctype', 'enterkeyhint', 'fetchpriority', 'for', 'form', 'formenctype', 'formmethod', 'formnovalidate', 'formtarget',
        'headers', 'headingoffset', 'headingreset', 'height', 'hidden', 'high', 'href', 'hreflang', 'http-equiv',
        'id', 'imagesizes', 'imagesrcset', 'inert', 'inputmode', 'integrity', 'ismap', 'itemid', 'itemprop', 'itemref',
        'itemscope', 'itemtype', 'kind', 'label', 'lang', 'list', 'loading', 'loop', 'low',
        'max', 'maxlength', 'media', 'method', 'min', 'minlength', 'multiple', 'muted', 'name', 'nomodule', 'nonce', 'novalidate',
        'open', 'optimum', 'pattern', 'ping', 'placeholder', 'playsinline', 'popover', 'popovertarget', 'popovertargetaction',
        'poster', 'preload', 'readonly', 'referrerpolicy', 'rel', 'required', 'reversed', 'rows', 'rowspan',
        'sandbox', 'scope', 'selected', 'shadowrootclonable', 'shadowrootcustomelementregistry', 'shadowrootdelegatesfocus',
        'shadowrootmode', 'shadowrootserializable', 'shadowrootslotassignment', 'shape', 'size', 'sizes', 'slot', 'span',
        'spellcheck', 'src', 'srclang', 'srcset', 'start', 'step', 'style', 'tabindex', 'target', 'title', 'translate', 'type',
        'usemap', 'value', 'width', 'wrap', 'writingsuggestions',

        // Accessibility roles, and the xml: forms of lang and space that Word and XHTML pastes carry
        'role', 'xml:lang', 'xml:space',

        // Obsolete presentational attributes browsers still render, and the fullscreen names in old video embed codes
        'align', 'axis', 'background', 'bgcolor', 'border', 'bordercolor', 'cellpadding', 'cellspacing', 'char', 'charoff',
        'clear', 'compact', 'face', 'frame', 'frameborder', 'hspace', 'longdesc', 'marginheight', 'marginwidth', 'noshade',
        'nowrap', 'rules', 'scrolling', 'summary', 'valign', 'vspace',
        'allowtransparency', 'mozallowfullscreen', 'webkitallowfullscreen',

        // VML drawings from Word pastes, and fill and stroke from the Outlook buttons in email templates. No current
        // browser draws VML; listed so a paste is not refused for markup nobody can see in the editor
        'arcsize', 'arrowok', 'aspectratio', 'coordsize', 'eqn', 'fill', 'fillcolor', 'filled', 'from', 'gradientshapeok',
        'inset', 'joinstyle', 'opacity', 'path', 'stroke', 'strokecolor', 'stroked', 'strokeweight', 'to',

        // Word content controls (<w:sdt>): placeholder and data-binding settings only Word reads
        'docpart', 'prefixmappings', 'sdttag', 'showingplchdr', 'storeitemid', 'temporary', 'text', 'xpath',

        // Word smart tag declarations (<o:SmartTagType namespaceuri="urn:schemas-microsoft-com:office:smarttags">)
        'namespaceuri',
    ];

    /**
     * Attribute names that pass with anything after the prefix: aria-* (read by screen readers, never run),
     * and Word's Office, VML and Word prefixes (o:spid, v:ext), which nothing outside Office reads.
     */
    public const ATTRIBUTE_PREFIXES = ['aria-', 'o:', 'v:', 'w:'];

    /**
     * Values an xmlns:* attribute may start with: the Microsoft Office namespaces Word pastes declare
     * (xmlns:o="urn:schemas-microsoft-com:office:office"). In a page served as XHTML, a prefix bound to
     * the XHTML namespace would make <o:script> a script.
     */
    public const OFFICE_NAMESPACES = ['urn:schemas-microsoft-com:', 'http://schemas.microsoft.com/office/'];

    /**
     * Attribute names that pass only when $allowForms is set.
     */
    public const FORM_ATTRIBUTES = ['formaction'];

    /**
     * Schemes refused at the start of every attribute value but style, URL attribute or not. The
     * browser ignores a javascript: in title= or value=, but a page script that copies the value
     * into a link or into location runs it, so no value may start with one. A style value is
     * checked as CSS instead, where url(javascript:...) is refused.
     */
    public const SCRIPT_SCHEMES = ['javascript'];

    /**
     * URL schemes allowed inside CSS url().
     */
    public const CSS_URL_SCHEMES = ['http', 'https'];

    /**
     * CSS functions allowed in <style> and style=, lowercase. Outside resources load through
     * functions (url(), image-set(), src()), so a function browsers add later is refused until
     * it is listed here. url() targets are checked on their own.
     */
    public const CSS_FUNCTIONS = [
        'url', 'format', 'local',                                                                 // references, @font-face
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark',   // colors
        'linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient',
        'matrix', 'matrix3d', 'translate', 'translatex', 'translatey', 'translatez', 'translate3d', 'scale', 'scalex', 'scaley',
        'scalez', 'scale3d', 'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy', 'perspective',
        'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
        'circle', 'ellipse', 'inset', 'polygon', 'path', 'rect',                                  // shapes, for clip-path and shape-outside
        'calc', 'var', 'min', 'max', 'clamp', 'minmax', 'repeat', 'fit-content', 'counter', 'counters',
        'cubic-bezier', 'steps',                                                                  // animation timing
        'not', 'is', 'where', 'has', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'lang', 'dir',   // selectors
    ];

    /**
     * CSS at-rules allowed in <style>, lowercase and without the @. Word pastes use font-face, page and
     * list; charset is read only at the start of a stylesheet file, so in a page it does nothing.
     */
    public const CSS_AT_RULES = ['font-face', 'page', 'list', 'media', 'supports', 'keyframes', '-webkit-keyframes', 'charset'];

    /**
     * Attributes current browsers resolve as URLs on an allowed element. Their values must have
     * no scheme or one in $urlSchemes; srcset and ping hold a list, and every URL in it is
     * checked. Every other attribute value only has to avoid SCRIPT_SCHEMES, so
     * title="Note: x" and data="Note: x" pass.
     */
    public const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'ping', 'srcset', 'cite', 'longdesc', 'background'];

    //endregion
    //region Patterns and Limits

    // a Word element name: the o: (Office), v: (VML drawing) or w: (Word) prefix, then letters and digits. A browser
    // makes an unknown element of it, which does nothing; the attributes get the normal rules
    private const WORD_ELEMENT = '/^[ovw]:[a-z][a-z0-9]*$/';

    // CSS comments and quoted strings, read left to right the way the CSS tokenizer does: a comment runs
    // to */ or the end of the text, a string to its closing quote or a bare newline, and \ escapes the
    // next character or up to six hex digits plus one whitespace after them (the escape eats that
    // whitespace, so "\a<newline>" does not end the string). An unquoted url() runs to its ) and is
    // stepped over, since /* or a quote inside one is part of the URL, not the start of a comment or
    // string. No banned token, function or at-rule can be spelled inside a comment or string, so both are removed
    private const CSS_COMMENT_OR_STRING = '/(?i:url)\(\s*+(?!["\'])(?:[^)\\\\]++|\\\\.)*+\)?+(*SKIP)(*FAIL)|\/\*(?:[^*]++|\*(?!\/))*+(?:\*\/|\z)|"(?:[^"\\\\\n]++|\\\\(?:[0-9a-fA-F]{1,6}[ \t\n]?|.))*+(?:"|\n|\z)|\'(?:[^\'\\\\\n]++|\\\\(?:[0-9a-fA-F]{1,6}[ \t\n]?|.))*+(?:\'|\n|\z)/s';

    // CSS the name allowlists cannot catch: backslash escapes (\6a avascript, and an escape could spell any name),
    // IE behavior: or -ms-behavior: (as the property name, so scroll-behavior passes) and Firefox -moz-binding,
    // which attach code through an allowed url(), and the two selectors that fire on page data so a url() can
    // report it: [attr^=value] with ^= $= *= and @font-face unicode-range.
    // The selector passes on style, src and class, which hold layout, image addresses and class names, never a
    // token: email templates use div[style*="margin: 16px 0"] and Wikipedia img[src*="logo"]. The lookahead
    // skips those names, with or without a namespace prefix (*|, ns| or |) and spaces around them.
    // The selector's name run stops at the next [ and is possessive, so a run of brackets or of letters costs
    // one step; a plain * would backtrack across everything after a [ with no ]= behind it. The run also
    // takes *| (the any-namespace prefix), since [*|value^= matches the same attribute
    private const CSS_FORBIDDEN = '/\\\\|-moz-binding|(?<![a-z0-9-])(?:-ms-)?behavior\s*:|\[(?!\s*+(?:(?:\*|[a-z0-9_-]*+)\|)?+(?:style|src|class)\s*+[\^$*]=)(?:[^\]=^$*\[]++|\*\|)*+[\^$*]=|unicode-range/i';

    // a CSS function name and its (, image-set( or --custom(, read with comments and strings removed. The lookbehind
    // makes it a whole name, so in x-rotate( the name is x-rotate, and not an at-rule, so @media(...) is not media(.
    // An unquoted url() is stepped over, since an ( inside it is part of the URL
    private const CSS_FUNCTION = '/(?<![\w@-])url\([^)]*+(*SKIP)(*FAIL)|(?<![\w@-])(-{0,2}+[a-z_][\w-]*+)\(/i';

    // a CSS at-rule name, @media or @import, read with comments and strings removed; an unquoted url() is stepped
    // over, since url(https://user@host/) holds an @ that starts nothing
    private const CSS_AT_RULE = '/(?<![\w@-])url\([^)]*+(*SKIP)(*FAIL)|@(-?+[a-z_][\w-]*+)/i';

    // a backslash inside a quoted url() argument: an escape there still spells a scheme, url("\6a avascript:")
    private const CSS_URL_ESCAPE = '/url\(\s*+["\'][^"\')\\\\]*+\\\\/i';

    // every url( in CSS, capturing a quoted argument up to its closing quote, whitespace included, since the
    // scheme is read with tabs and newlines dropped (url("java\tscript:")), and an unquoted one up to the paren or whitespace
    private const CSS_URL = '/url\(\s*+(?|"([^"]*+)|\'([^\']*+)|([^"\')\s]*+))/i';

    // the longest run of well-formed UTF-8 from the start; the byte after the match is the first bad one
    private const UTF8_PREFIX = '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

    // C0 control characters other than tab, LF and CR
    private const CONTROL_CHARACTER = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    // Switches. Set once at startup; every check() call reads them.
    public static bool $allowForms          = false;   // form elements and formaction
    public static bool $allowStyleAttribute = true;    // style="...", through the CSS check; it styles its own element only
    public static bool $allowStyleElement   = true;    // <style>, through the CSS check; its rules can style anything on the page
    public static bool $allowEmbeds         = true;    // <iframe> whose src host is in $iframeHosts

    /**
     * @var string[] hosts an <iframe src> may point at, matched exactly and case-insensitively
     */
    public static array $iframeHosts = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];

    /**
     * @var string[] URL schemes a URL attribute may start with; any other scheme is refused, and a value with no
     *               scheme passes. Add what your visitors' machines should open on a click (sms, whatsapp, an app
     *               link). Read lowercase, and javascript is refused whatever this holds
     */
    public static array $urlSchemes = ['http', 'https', 'mailto', 'tel'];

    // Limits
    public static int $maxErrors       = 50;    // distinct errors reported per check; below 1 counts as 1
    public static int $maxDetailLength = 80;    // characters of content quoted in an error message before "..."

    // Text and tags the rules can never refuse are stepped over in one regex match instead of one token at a time.
    // Off, every byte goes through the tokenizer: the same result, slower. For checking a difference you suspect.
    public static bool $fastPath = true;

    //endregion
    //region Public API

    /**
     * Checks an HTML string. Never throws; every problem is a Violation in the Result.
     *
     *     $result = HtmlSecurityCheck::check('<p onclick="x()">Hi</p>');
     *     $result->ok;                   // false
     *     $result->errors[0]->message;   // 'onclick= event handler attributes are not allowed'
     */
    public static function check(string $html): Result
    {
        return (new self())->run($html);
    }

    /**
     * Returns the rule tables and the current switches, keyed by name: elements, formElements,
     * attributes, attributePrefixes, officeNamespaces, formAttributes, urlSchemes, scriptSchemes, cssUrlSchemes,
     * cssFunctions, cssAtRules, urlAttributes, allowForms, allowStyleAttribute, allowStyleElement, allowEmbeds, iframeHosts. For documentation, debugging and settings pages.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'elements'            => self::ELEMENTS,
            'formElements'        => self::FORM_ELEMENTS,
            'attributes'          => self::ATTRIBUTES,
            'attributePrefixes'   => self::ATTRIBUTE_PREFIXES,
            'officeNamespaces'    => self::OFFICE_NAMESPACES,
            'formAttributes'      => self::FORM_ATTRIBUTES,
            'urlSchemes'          => self::urlSchemes(),
            'scriptSchemes'       => self::SCRIPT_SCHEMES,
            'cssUrlSchemes'       => self::CSS_URL_SCHEMES,
            'cssFunctions'        => self::CSS_FUNCTIONS,
            'cssAtRules'          => self::CSS_AT_RULES,
            'urlAttributes'       => self::URL_ATTRIBUTES,
            'allowForms'          => self::$allowForms,
            'allowStyleAttribute' => self::$allowStyleAttribute,
            'allowStyleElement'   => self::$allowStyleElement,
            'allowEmbeds'         => self::$allowEmbeds,
            'iframeHosts'         => self::$iframeHosts,
        ];
    }

    //endregion
    //region Check Flow

    /**
     * @var array<string, Violation> keyed by code and detail, so the same problem reports once
     */
    private array $errors = [];

    private string $html = '';

    /** True for the check of a conditional comment's inner markup, which reads no conditional comment inside it */
    private bool $insideConditionalComment = false;

    /** $maxErrors as this check reads it: at least 1, so content a rule refuses is never reported as passing */
    private readonly int $errorLimit;

    private function __construct()
    {
        $this->errorLimit = max(1, self::$maxErrors);
    }

    private function run(string $html): Result
    {
        try {
            if ($this->bytesAllowTokenizing($html)) {
                $this->html = str_replace(["\r\n", "\r"], "\n", $html);   // so token offsets match the string the details are cut from
                $this->walk();
            }
        } catch (RuntimeException $e) {   // a regex stopped at a PCRE limit, so the rest of the content was never read
            $this->fail('not-checked', $e->getMessage());
        }
        return new Result(array_values($this->errors));
    }

    /**
     * Invalid UTF-8 and control characters are refused before tokenizing, and nothing else is checked when they are
     */
    private function bytesAllowTokenizing(string $html): bool
    {
        if (!preg_match('//u', $html)) {   // PCRE's own UTF-8 check: a plain loop with no limit to reach, whatever the size
            $validLength = self::validUtf8Length($html);
            $bad         = substr($html, $validLength, 16);   // a fixed cut: the bytes are not UTF-8, so excerpt() cannot count characters
            $this->fail('not-utf8', $bad . (strlen($html) - $validLength > 16 ? '...' : ''));
            return false;
        }
        $found = preg_match(self::CONTROL_CHARACTER, $html, $match, PREG_OFFSET_CAPTURE);
        if ($found === false) {
            throw new RuntimeException(preg_last_error_msg());
        }
        if ($found === 1) {
            $this->fail('control-character', $match[0][0] . ' at byte ' . $match[0][1]);
            return false;
        }
        return true;
    }

    /**
     * Bytes of well-formed UTF-8 before the first bad one, for content the //u check refused
     */
    private static function validUtf8Length(string $html): int
    {
        $length = 0;
        while (preg_match(self::UTF8_PREFIX, substr($html, $length, 65536), $match) && $match[0] !== '') {   // 64 KB at a time: over a megabyte at once the interpreter reaches pcre.backtrack_limit
            $length += strlen($match[0]);
        }
        return $length;
    }

    private function walk(): void
    {
        $rawText = '';
        foreach ((new Tokenizer($this->html, skip: self::$fastPath ? self::knownSafePattern() : null))->tokens() as $token) {
            if (count($this->errors) >= $this->errorLimit) {
                return;
            }
            if ($token->type === Token::TEXT) {
                if ($rawText !== '') {
                    $this->checkNoLessThan($token->start, $token->end, allowStyleComment: $rawText === 'style');
                    if ($rawText === 'style') {
                        $this->checkCss($token->data);
                    }
                }
                continue;
            }
            $rawText = '';
            if ($token->type === Token::START_TAG) {
                $allowed = $this->checkStartTag($token);
                $rawText = $allowed && in_array($token->name, ['style', 'iframe', 'textarea'], true) ? $token->name : '';   // the allowed elements whose content is text to the end tag; a refused one reports once
            } elseif ($token->type === Token::COMMENT) {
                if (substr($this->html, $token->start, 4) !== '<!--') {   // <?php ...>, <!x ...> and </ x>: comments to a browser, ending at the first >
                    if (strncasecmp($token->data, '?import', 7) === 0) {   // <?import namespace="x" implementation="..."> bound a behavior to a prefix in IE 5.5 to 9
                        $this->fail('element-not-allowed', self::excerpt($this->source($token)));
                    } else {
                        $this->checkNoLessThan($token->start + 2, $token->end);
                    }
                } elseif (strncasecmp($token->data, '[if', 3) === 0 && !$this->insideConditionalComment) {   // a nested one never closes, since the outer comment ends at the first -->, so it reports as unclosed-markup and is not read again
                    $this->checkConditionalComment($token->data);
                }
            } elseif ($token->type === Token::DOCTYPE) {
                $this->fail('element-not-allowed', self::excerpt($this->source($token)));
            } elseif ($token->type === Token::UNCLOSED) {
                $this->fail('unclosed-markup', self::excerpt($this->source($token)));   // the page this is printed into would be read as part of it
            }
        }
    }

    /**
     * Text a browser never reads as markup (raw-text content, a comment that is not <!-- -->) is markup to a
     * parser without those rules: strip_tags() with an allow list, or an HTML4-era DOM. So a < is refused there.
     * The bytes as written are checked, so &lt; in a textarea passes.
     *
     * In <style>, a <!-- whose --> is in the same block passes: Word wraps its stylesheet in
     * <style><!-- ... --></style>. strip_tags() reads it as a comment to the first -->, so that has to be
     * inside the block too: a --> later in an attribute value would end the comment there and leave the
     * rest of the value as a live tag. Any other < in the block is still refused.
     */
    private function checkNoLessThan(int $from, int $end, bool $allowStyleComment = false): void
    {
        $lessThan = strpos($this->html, '<', $from);
        $close    = -1;
        while ($allowStyleComment && $lessThan !== false && substr($this->html, $lessThan, 4) === '<!--') {
            if ($close < $lessThan + 2) {   // the next --> serves every <!-- before it, so each search starts past the last
                $close = strpos($this->html, '-->', $lessThan + 2);   // where strip_tags() ends it: <!--> closes at once
            }
            if ($close === false || $close + 3 > $end) {
                break;
            }
            $lessThan = strpos($this->html, '<', $lessThan + 4);
        }
        if ($lessThan !== false && $lessThan < $end) {
            $this->fail('less-than-in-text', self::excerpt(substr($this->html, $lessThan, $end - $lessThan)));
        }
    }

    /**
     * The markup inside <!--[if ...]> ... <![endif]-->, checked with the same rules as the content around it.
     * A comment to every browser since IE 10, but the IE engine inside old Windows programs and Outlook's Word
     * engine read it as markup, and email templates rely on that for Outlook-only tables and VML buttons
     */
    private function checkConditionalComment(string $comment): void
    {
        $open = strpos($comment, ']>');
        if ($open === false) {
            return;
        }
        $inner = substr($comment, $open + 2);
        $close = stripos($inner, '<![endif]');
        if ($close !== false) {
            $inner = substr($inner, 0, $close);
        }
        $check                           = new self();
        $check->insideConditionalComment = true;
        foreach ($check->run($inner)->errors as $violation) {
            if (count($this->errors) >= $this->errorLimit) {
                return;
            }
            $this->errors["$violation->code\0$violation->detail"] ??= $violation;
        }
    }

    private function fail(string $code, string $detail): void
    {
        if (count($this->errors) >= $this->errorLimit) {
            return;   // the walk stops at the cap too, but one tag can add several errors before it checks
        }
        // one line of valid UTF-8 whatever the content held, so a log line or an error page can show it as is
        $detail = addcslashes($detail, "\0..\37\177");
        if (!preg_match('//u', $detail)) {
            $detail = addcslashes($detail, "\200..\377");
        }
        $this->errors["$code\0$detail"] ??= new Violation($code, $detail);
    }

    //endregion
    //region Fast Path

    /**
     * @var array<string, string> the known-safe regex by the settings it reads ($allowStyleAttribute and $urlSchemes), built on first use
     */
    private static array $knownSafe = [];

    /**
     * An anchored regex for the markup the rules can never refuse, which the tokenizer steps over
     * in one match instead of one token at a time: text, end tags, and start tags of listed elements
     * whose attributes are double-quoted and hold nothing a rule looks at. Everything else, including
     * every element that switches the tokenizer's state, custom elements, unquoted values and
     * character references, falls through to the tokenizer and is checked as usual, so the rules stay
     * the only place a decision is made. FastPathTest proves every run this matches passes on its own.
     */
    private static function knownSafePattern(): string
    {
        $styles  = (int)self::$allowStyleAttribute;
        $schemes = self::urlSchemes();
        $key     = $styles . ' ' . implode(' ', $schemes);
        if (isset(self::$knownSafe[$key])) {
            return self::$knownSafe[$key];
        }
        $space = '[\t\n\f ]';
        // a value with no character reference but &amp; (a reference can decode to anything) and no colon, so no scheme
        $value = '(?:[^"&<>:]|&amp;)*+';
        // a URL attribute value may start with a listed scheme; the rest is a plain value
        $url = '(?:(?:' . implode('|', array_map(preg_quote(...), $schemes)) . '):)?+' . $value;
        // CSS with none of the punctuation the CSS check reads (\ escapes, ( every function, @ at-rules, [ selectors,
        // & references) and none of the three bare words on CSS_FORBIDDEN
        $css = '(?:(?!-moz-binding|(?<![a-z0-9-])(?:-ms-)?behavior\s*+:|unicode-range)[^"\\\\()@\[&<>])*+';
        // style="css" when styles are on; a URL attribute with a url; a common name or aria-*, with a value
        $urlNames   = array_diff(self::URL_ATTRIBUTES, self::FORM_ATTRIBUTES);
        // only the names editors write most, and only while they are listed: a tag with any other name gets the same
        // check from the tokenizer, and listing every allowed name made the regex slower and 500 KB bigger under PCRE's JIT
        $plainNames = array_intersect(['class', 'id', 'title', 'alt', 'width', 'height', 'lang', 'align', 'valign', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'target', 'rel'], self::ATTRIBUTES);
        $attribute  = ($styles ? 'style="' . $css . '"|' : '')
            . '(?:' . implode('|', $urlNames) . ')="' . $url . '"'
            . '|(?:' . implode('|', $plainNames) . '|aria-[a-z0-9-]*+)="' . $value . '"';
        // text up to a <, a < that starts nothing (text too), an end tag, or a listed element's start tag with
        // space-separated attributes; as many of those as follow. The lone < needs its next byte in view: the tokenizer
        // may run this over a window of the input, and a < on the window's edge could be the start of <script>
        return self::$knownSafe[$key] = '~(?:[^<]++|<(?=[^!/?a-z])|</[a-z][a-z0-9]*+>|<(?:' . implode('|', self::ELEMENTS) . ')(?:' . $space . '++(?:' . $attribute . '))*+' . $space . '*+/?>)++~Ai';
    }

    //endregion
    //region Token Rules

    /**
     * A refused element reports once and its attributes are not checked, so <script onload> gives one message.
     * Returns whether the element is allowed
     */
    private function checkStartTag(Token $token): bool
    {
        $name = $token->name;
        if (!$this->elementAllowed($name)) {
            $this->fail('element-not-allowed', self::excerpt($this->source($token)));
            return false;
        }
        if ($name === 'iframe') {
            $this->checkIframe($token);
        }
        foreach ($token->attributes as $attribute => $value) {
            $this->checkAttribute($name, (string)$attribute, $value);   // PHP stores a name of digits only, <p 1="x">, as an int key
        }
        return true;
    }

    private function elementAllowed(string $name): bool
    {
        if (in_array($name, self::ELEMENTS, true)) {
            return true;
        }
        return match ($name) {
            'style'  => self::$allowStyleElement,
            'iframe' => self::$allowEmbeds,
            default  => (self::$allowForms && in_array($name, self::FORM_ELEMENTS, true))
                        || preg_match(self::WORD_ELEMENT, $name) === 1,
        };
    }

    private function checkAttribute(string $element, string $attribute, string $value): void
    {
        if (str_starts_with($attribute, 'on')) {
            $this->fail('event-handler', self::excerpt($attribute));
            return;
        }
        if (!self::attributeAllowed($attribute, $value)) {
            $this->fail('attribute-not-allowed', self::excerpt(str_starts_with($attribute, 'xmlns:') ? "$attribute=\"$value\"" : $attribute));
            return;
        }
        if ($attribute === 'style') {
            $this->checkCss($value);
            return;
        }
        // srcset and ping are lists: ping splits at spaces, srcset at commas and at the spaces before each descriptor
        $isList = $attribute === 'srcset' || $attribute === 'ping';
        $urls   = $isList ? preg_split('/[\s,]++/', $value, -1, PREG_SPLIT_NO_EMPTY) : [$value];
        if ($urls === false) {
            throw new RuntimeException(preg_last_error_msg());
        }
        foreach ($urls as $url) {
            $scheme = self::scheme($url);
            if ($scheme === null) {
                continue;
            }
            if ($scheme === 'data' && $element === 'img' && $attribute === 'src' && str_starts_with(strtolower(self::compact($url)), 'data:image/')) {
                continue;   // inline image data: a browser decodes an img resource as a picture and nothing else, whatever the type
            }
            $allowed = in_array($attribute, self::URL_ATTRIBUTES, true)
                ? in_array($scheme, self::urlSchemes(), true)
                : !in_array($scheme, self::SCRIPT_SCHEMES, true);
            if (!$allowed) {
                $this->fail('url-scheme-not-allowed', self::excerpt("$attribute=\"$value\""));
                return;
            }
        }
    }

    /**
     * A listed name, a listed prefix, an xmlns:* declaration of an Office namespace, or formaction while
     * $allowForms is on. style is listed but passes only while $allowStyleAttribute is on
     */
    private static function attributeAllowed(string $attribute, string $value): bool
    {
        if (in_array($attribute, self::ATTRIBUTES, true)) {
            return $attribute !== 'style' || self::$allowStyleAttribute;
        }
        if (str_starts_with($attribute, 'xmlns:')) {
            return self::startsWithAny($value, self::OFFICE_NAMESPACES);
        }
        return self::startsWithAny($attribute, self::ATTRIBUTE_PREFIXES)
            || (self::$allowForms && in_array($attribute, self::FORM_ATTRIBUTES, true));
    }

    /**
     * src must be an absolute http, https or protocol-relative URL on a listed host; no src is refused too
     */
    private function checkIframe(Token $token): void
    {
        // what a browser strips from a URL before parsing: spaces and controls at the ends, tabs and newlines
        // anywhere (a CR here came from &#13;). A space inside stays, so / /host/x is a path on this site, not a host
        $src   = str_replace(["\t", "\n", "\r"], '', trim($token->attributes['src'] ?? '', "\x00..\x20"));
        $hosts = implode('|', array_map(fn(string $host) => preg_quote(strtolower($host), '/'), self::$iframeHosts));
        // http://, https:// or // then a listed host, then the end of the host part (a slash, ?, # or nothing)
        if ($hosts === '' || !preg_match("/^(?:https?:)?\/\/(?:$hosts)(?=[\/?#]|$)/i", $src)) {
            $this->fail('iframe-host', $src === '' ? '(no src)' : self::excerpt($src));
        }
    }

    //endregion
    //region Value Rules

    private function checkCss(string $css): void
    {
        // comments and strings are removed, not blanked to a space: the CSS tokenizer drops a comment without leaving
        // whitespace, so [*/**/|x^=a] reads [*|x^=a] to a browser, and joining the text around a string can only add a
        // match. Past the PCRE limits (megabytes inside one comment or string) the text is checked as written, which is stricter
        $code  = preg_replace(self::CSS_COMMENT_OR_STRING, '', $css) ?? $css;
        $found = preg_match(self::CSS_FORBIDDEN, $code, $match);
        if ($found === 1) {
            $this->fail('css-not-allowed', self::excerpt($match[0]));
        } elseif ($found === false) {
            $this->fail('css-not-allowed', self::excerpt($css));   // the PCRE limit (megabytes of x*| pairs inside one [): a block too big to check is refused, never passed
        }
        $this->checkCssNames($css, $code, self::CSS_FUNCTION, self::CSS_FUNCTIONS);
        $this->checkCssNames($css, $code, self::CSS_AT_RULE, self::CSS_AT_RULES);
        $found = preg_match(self::CSS_URL_ESCAPE, $css, $match);
        if ($found !== 0) {   // false (a PCRE limit) refuses too
            $this->fail('css-not-allowed', self::excerpt($match[0] ?? $css));
        }
        if (preg_match_all(self::CSS_URL, $css, $matches) === false) {
            $this->fail('css-not-allowed', self::excerpt($css));
            return;
        }
        foreach ($matches[1] as $url) {
            $scheme = self::scheme($url);
            if ($scheme !== null && !in_array($scheme, self::CSS_URL_SCHEMES, true)) {
                $this->fail('css-not-allowed', self::excerpt("url($url)"));
            }
        }
    }

    /**
     * Refuses every function or at-rule name in $code (the CSS with comments and strings removed)
     * that is not in $allowed, with the name as written as the detail: image-set( or @import.
     *
     * @param string[] $allowed
     */
    private function checkCssNames(string $css, string $code, string $pattern, array $allowed): void
    {
        if (preg_match_all($pattern, $code, $matches) === false) {   // a PCRE limit was reached, so the names are unknown
            $this->fail('css-not-allowed', self::excerpt($css));
            return;
        }
        foreach ($matches[1] as $index => $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $this->fail('css-not-allowed', self::excerpt($matches[0][$index]));
            }
        }
    }

    /**
     * $urlSchemes as the check reads it: lowercase, and never a script scheme, so a site that lists
     * javascript by mistake keeps the one promise the library makes
     *
     * @return string[]
     */
    private static function urlSchemes(): array
    {
        return array_values(array_diff(array_map(strtolower(...), self::$urlSchemes), self::SCRIPT_SCHEMES));
    }

    /**
     * The lowercased scheme a browser would read from a URL value, or null when the value has
     * none (a relative path, #id, ?query, //host, or plain text). Browsers drop ASCII whitespace
     * and control characters from a URL before reading it, so "java\nscript:" is javascript.
     */
    private static function scheme(string $value): ?string
    {
        $value = self::compact($value);
        // a scheme is a letter followed by letters, digits, + - or . up to the first colon
        $found = preg_match('/^([a-z][a-z0-9+.\-]*+):/i', $value, $match);
        if ($found === false) {
            throw new RuntimeException(preg_last_error_msg());
        }
        return $found === 1 ? strtolower($match[1]) : null;
    }

    /**
     * The value with ASCII whitespace and control characters removed, as a browser reads a URL
     */
    private static function compact(string $value): string
    {
        return preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? throw new RuntimeException(preg_last_error_msg());
    }

    //endregion
    //region Helpers

    /**
     * The tag as written in the content, after CR and CRLF became LF
     */
    private function source(Token $token): string
    {
        return substr($this->html, $token->start, $token->end - $token->start);
    }

    /**
     * True when $value starts with one of $prefixes
     *
     * @param string[] $prefixes
     */
    private static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first $maxDetailLength characters of a value for an error message, cut on a UTF-8 boundary.
     */
    private static function excerpt(string $value): string
    {
        preg_match('/^.{0,' . self::$maxDetailLength . '}/us', $value, $match);
        $cut = $match[0] ?? substr($value, 0, self::$maxDetailLength);   // past a PCRE limit, a byte cut: fail() escapes a split character
        return strlen($cut) < strlen($value) ? "$cut..." : $cut;
    }

    //endregion
}
