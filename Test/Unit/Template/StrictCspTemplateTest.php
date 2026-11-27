<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Template;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hyvä strict CSP rules for every template in this package.
 *
 * 1. An inline script runs only when Hyvä registers it (nonce or hash), so each one must be
 *    followed directly by $hyvaCsp->registerInlineScript().
 * 2. Hyvä's helper puts its nonce on (or hashes from) the LAST "<script" in the output so far, so
 *    no script body may contain that text.
 * 3. The Alpine CSP build cannot evaluate expressions, so an Alpine attribute may hold only a
 *    property or method name (dotted paths allowed), or "item in list" for x-for. x-data must
 *    name a component the same template registers with Alpine.data().
 * 4. No inline on* event handler and no x-html.
 *
 * @coversNothing
 */
#[CoversNothing]
class StrictCspTemplateTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../view/frontend/templates';

    private const NAME = '[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*';

    /**
     * Inline scripts and Alpine roots each template is expected to hold, so a scan that matches
     * nothing cannot pass.
     */
    private const EXPECTED = [
        'cart/gift-popup.phtml'    => ['scripts' => 1, 'components' => ['freeGiftPopup']],
        'cart/progress-bar.phtml'  => ['scripts' => 1, 'components' => ['freeGiftProgressBar']],
        'product/gift-label.phtml' => ['scripts' => 0, 'components' => []],
        'widget/countdown.phtml'   => ['scripts' => 1, 'components' => ['freeGiftCountdown']],
    ];

    /**
     * The template with HTML comments and PHP/JS block comments removed, so prose such as
     * "the trigger used to sit inside x-if=..." is not read as markup.
     *
     * @param string $html
     * @return string
     */
    private static function withoutComments(string $html): string
    {
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);

        return (string) preg_replace('#/\*.*?\*/#s', '', $html);
    }

    /**
     * Inline scripts in an HTML fragment that are not followed by a registration call.
     *
     * @param string $html
     * @return string[]
     */
    public static function unregisteredInlineScripts(string $html): array
    {
        preg_match_all(
            '#<script\b([^>]*)>(.*?)</script>(\s*)(<\?php\s+\$hyvaCsp->registerInlineScript\(\)\s*;?\s*\?>)?#is',
            self::withoutComments($html),
            $all,
            PREG_SET_ORDER
        );
        $bad = [];
        foreach ($all as $m) {
            $isExternal = (bool) preg_match('#\bsrc\s*=#i', $m[1]);
            if (!$isExternal && empty($m[4])) {
                $bad[] = substr(trim($m[0]), 0, 120);
            }
        }

        return $bad;
    }

    /**
     * Inline scripts whose body contains "<script".
     *
     * @param string $html
     * @return string[]
     */
    public static function scriptsMentioningScriptTags(string $html): array
    {
        // Raw file, comments included: a "<script" inside a JS comment still takes the nonce.
        preg_match_all('#^\s*<script\b[^>]*>(.*?)</script>#ims', (string) preg_replace('#<!--.*?-->#s', '', $html), $all, PREG_SET_ORDER);
        $bad = [];
        foreach ($all as $m) {
            if (stripos($m[1], '<script') !== false) {
                $bad[] = substr(trim($m[1]), 0, 60);
            }
        }

        return $bad;
    }

    /**
     * Alpine attributes whose value is an expression rather than a name. Single- and
     * double-quoted values are both read.
     *
     * @param string $html
     * @return string[]
     */
    public static function alpineExpressions(string $html): array
    {
        preg_match_all(
            '#\s((?:x-[\w:.-]+|@[\w.:-]+|:[\w.-]+))=(?:"([^"]*)"|\'([^\']*)\')#',
            self::withoutComments($html),
            $all,
            PREG_SET_ORDER
        );
        $bad = [];
        foreach ($all as $m) {
            $attr = $m[1];
            $value = trim(($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
            if (str_starts_with($attr, 'x-transition') || $attr === 'x-ref') {
                continue;
            }
            $ok = $attr === 'x-for'
                ? (bool) preg_match('#^' . self::NAME . '\s+in\s+' . self::NAME . '$#', $value)
                : ($value === '' || (bool) preg_match('#^' . self::NAME . '$#', $value));
            if (!$ok) {
                $bad[] = $attr . '="' . $value . '"';
            }
        }

        return $bad;
    }

    /**
     * Markup the CSP forbids outright: inline on* handlers and x-html.
     *
     * @param string $html
     * @return string[]
     */
    public static function forbiddenMarkup(string $html): array
    {
        preg_match_all('#<[a-z][^>]*?\s(on[a-z]+|x-html)\s*=#i', self::withoutComments($html), $all);

        return $all[1];
    }

    /**
     * Names used in x-data attributes.
     *
     * @param string $html
     * @return string[]
     */
    public static function xDataNames(string $html): array
    {
        preg_match_all('#\sx-data=(?:"([^"]*)"|\'([^\']*)\')#', self::withoutComments($html), $all, PREG_SET_ORDER);
        $names = [];
        foreach ($all as $m) {
            $names[] = trim(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
        }

        return array_values(array_unique($names));
    }

    /**
     * Names registered with Alpine.data('name', ...).
     *
     * @param string $html
     * @return string[]
     */
    public static function registeredComponents(string $html): array
    {
        preg_match_all('#Alpine\.data\(\s*[\'"]([A-Za-z_$][\w$]*)[\'"]#', $html, $all);

        return array_values(array_unique($all[1]));
    }

    /**
     * Every template in this package, keyed by its path under templates/.
     *
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'phtml') {
                $out[substr($file->getPathname(), strlen(self::TEMPLATES) + 1)] = [$file->getPathname()];
            }
        }
        ksort($out);

        return $out;
    }

    public function testEveryTemplateIsChecked(): void
    {
        self::assertSame(array_keys(self::EXPECTED), array_keys(self::templates()));
    }

    /**
     * Each inline script is registered and names no script tag.
     *
     * @dataProvider templates
     * @param string $path
     */
    #[DataProvider('templates')]
    public function testEveryInlineScriptIsRegistered(string $path): void
    {
        $html = (string) file_get_contents($path);
        $key = substr($path, strlen(self::TEMPLATES) + 1);
        self::assertSame(
            self::EXPECTED[$key]['scripts'],
            preg_match_all('#^\s*<script\b#im', self::withoutComments($html)),
            'the check must see the scripts'
        );
        self::assertSame([], self::unregisteredInlineScripts($html));
        self::assertSame([], self::scriptsMentioningScriptTags($html));
    }

    /**
     * Each Alpine attribute names a property or method; each x-data names a registered component.
     *
     * @dataProvider templates
     * @param string $path
     */
    #[DataProvider('templates')]
    public function testNoAlpineAttributeHoldsAnExpression(string $path): void
    {
        $html = (string) file_get_contents($path);
        $key = substr($path, strlen(self::TEMPLATES) + 1);
        self::assertSame([], self::alpineExpressions($html));
        self::assertSame([], self::forbiddenMarkup($html));
        self::assertSame(self::EXPECTED[$key]['components'], self::xDataNames($html), 'x-data names');
        foreach (self::xDataNames($html) as $name) {
            self::assertContains($name, self::registeredComponents($html), "x-data=\"$name\" is not registered");
        }
    }

    public function testTheChecksCatchWhatTheyAreFor(): void
    {
        // Positive controls: each rule must flag a planted violation, and pass its fixed form.
        self::assertCount(1, self::unregisteredInlineScripts('<script>var a = 1;</script><div></div>'));
        self::assertSame([], self::unregisteredInlineScripts(
            "<script>var a = 1;</script>\n<?php \$hyvaCsp->registerInlineScript() ?>"
        ));
        self::assertSame([], self::unregisteredInlineScripts('<script src="/x.js"></script>'));
        self::assertCount(1, self::scriptsMentioningScriptTags("<script>\n// strips <script> tags\n</script>"));
        self::assertCount(1, self::scriptsMentioningScriptTags("<script>\n/* a <script> tag */\n</script>"));
        self::assertSame([], self::scriptsMentioningScriptTags("<script>\n// strips script tags\n</script>"));
        self::assertCount(1, self::unregisteredInlineScripts(
            "<!-- does not run <script> tags --><script>var a;</script>\n<p></p>"
            . "<script>var b;</script>\n<?php \$hyvaCsp->registerInlineScript() ?>"
        ));

        self::assertSame(
            [
                'x-data="freeGiftCountdown(<?= (int) $endTimestamp ?>)"',
                'x-show="!expired"',
                '@private-content-loaded.window="onPrivateContent($event.detail.data)"',
                ':style="\'width: \' + Math.min(100, bar.percentage) + \'%;\'"',
                '@click.self="open = false"',
                'x-data="freeGiftPopup(1, 2)"',
                'x-for="(gift, i) in gifts"',
            ],
            self::alpineExpressions(
                '<b x-data="freeGiftCountdown(<?= (int) $endTimestamp ?>)" x-show="!expired"'
                . ' @private-content-loaded.window="onPrivateContent($event.detail.data)"'
                . ' :style="\'width: \' + Math.min(100, bar.percentage) + \'%;\'"'
                . ' @click.self="open = false" x-data=\'freeGiftPopup(1, 2)\' x-for="(gift, i) in gifts"></b>'
            )
        );
        self::assertSame([], self::alpineExpressions(
            '<b @click="openDialog" x-show="triggerVisible" :src="giftImageUrl" x-cloak :key="gift.sku"'
            . ' x-for="option in gift.options" x-data="freeGiftPopup" x-ref="dialog"'
            . ' @private-content-loaded.window="onPrivateContent"></b>'
            . '<?php /* used to be x-if="gifts.length > 0" */ ?><!-- x-show="!open" -->'
        ));
        self::assertSame(['onclick', 'x-html'], self::forbiddenMarkup('<a onclick="go()"></a><div x-html="html"></div>'));
        self::assertSame([], self::forbiddenMarkup('<a data-onclick="x" class="on"></a>'));
        self::assertSame(['a', 'b'], self::xDataNames('<i x-data="a"></i><i x-data=\'b\'></i><i x-data="a"></i>'));
        self::assertSame(['a'], self::registeredComponents("Alpine.data('a', fn); Alpine.data(\"a\", fn);"));
    }
}
