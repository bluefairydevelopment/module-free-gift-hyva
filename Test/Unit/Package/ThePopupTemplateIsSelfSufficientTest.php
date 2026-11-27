<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * TWO DEFECTS THAT ONLY A BROWSER FOUND, HELD HERE SO A READER CANNOT REINTRODUCE THEM.
 *
 * Neither was visible to phpunit, phpcs or phpstan, because both live in a .phtml file's
 * JavaScript and in what a browser does with it.
 *
 * — the dialog carried `x-trap.noscroll`, a directive belonging to Alpine's Focus
 * plugin. The plugin is not installed, and no file in the Hyvä theme uses x-trap either, so
 * it never would be. Alpine logged a warning 41 times per cart page and did nothing: no focus
 * trap on a dialog a shopper must choose in, and no scroll lock behind it.
 *
 * — the trigger button sat inside `x-if="gifts.length > 0"`, so the component had to
 * fetch every gift on init just to decide whether to draw a button. Hyvä rebuilds the cart DOM
 * every ~2.5s, so that was four uncached POSTs per ten seconds for every shopper, opened or
 * not.
 */
class ThePopupTemplateIsSelfSufficientTest extends TestCase
{
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function popup(): string
    {
        return (string) file_get_contents(
            self::packageRoot() . '/view/frontend/templates/cart/gift-popup.phtml'
        );
    }

    /**
     * No template may use a directive whose Alpine plugin this package does not ship.
     *
     * Asserted over EXECUTABLE markup, not the raw file: the fix's own explanation names
     * x-trap several times, and a naive `str_contains` would fail on the comment that
     * explains why the directive is gone.
     */
    public function testNoTemplateUsesAnAlpineDirectiveWhosePluginIsNotInstalled(): void
    {
        $templates = (array) glob(self::packageRoot() . '/view/frontend/templates/*/*.phtml');
        self::assertNotEmpty($templates, 'CONTROL: no templates found, so this test examined nothing');

        $examined = 0;
        foreach ($templates as $template) {
            $markup = self::withoutComments((string) file_get_contents((string) $template));
            foreach (['x-trap', 'x-intersect', 'x-collapse', 'x-persist'] as $directive) {
                self::assertDoesNotMatchRegularExpression(
                    '/\s' . preg_quote($directive, '/') . '[\s.=]/',
                    $markup,
                    basename((string) $template) . " uses {$directive}, which needs an Alpine plugin "
                    . 'this package neither ships nor declares. It fails silently: a console warning '
                    . 'and no behaviour.'
                );
            }
            $examined++;
        }
        self::assertSame(count($templates), $examined);
    }

    /** CONTROL: the predicate above must actually match a directive when one is present. */
    public function testTheDirectiveScanCanFindADirective(): void
    {
        $specimen = '<div x-show="open" x-trap.noscroll="open" role="dialog">';
        self::assertMatchesRegularExpression(
            '/\sx-trap[\s.=]/',
            self::withoutComments($specimen),
            'CONTROL: the scan cannot see an x-trap that is really there'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\sx-trap[\s.=]/',
            self::withoutComments("// the element used to carry x-trap.noscroll here\n<div x-show=\"open\">"),
            'CONTROL: the comment stripper left a directive name from a comment'
        );
    }

    /**
     * The dialog must still trap focus and lock scroll — the behaviours x-trap was there for.
     * Removing the directive without replacing them would be a silent accessibility
     * regression that the test above would happily call a pass.
     */
    public function testTheDialogStillTrapsFocusAndLocksScroll(): void
    {
        $popup = self::popup();
        self::assertStringContainsString('trapFocus', $popup, 'the dialog no longer traps focus at all');
        self::assertStringContainsString('releaseFocus', $popup, 'nothing releases the focus trap');
        self::assertStringContainsString(
            "document.body.style.overflow = 'hidden'",
            $popup,
            'the background is no longer scroll-locked while the dialog is open'
        );
        // Extracted rather than distance-matched: the first version of this used
        // `[\s\S]{0,400}` and failed on nothing worse than the explanatory comment inside
        // destroy() growing. A magic character budget is not a property of the code.
        $destroyBody = self::bodyOf($popup, 'destroy');
        self::assertNotSame('', $destroyBody, 'no destroy() handler found at all');
        self::assertStringContainsString(
            'releaseFocus',
            $destroyBody,
            'destroy() does not release the trap, so a component torn down while open leaves '
            . 'document.body locked at overflow:hidden with nothing left to unlock it'
        );
    }

    /**
     * The gift list must not be fetched on init.
     *
     * Reading `init` alone is not enough — this asserts both halves of the fix: init does
     * not fetch, and the trigger is no longer gated on the list having been fetched.
     */
    public function testTheGiftListIsNotFetchedUntilTheShopperOpensTheDialog(): void
    {
        $popup = self::withoutComments(self::popup());

        self::assertDoesNotMatchRegularExpression(
            '/init:\s*function\s*\([^)]*\)\s*\{[^}]*fetchGifts\(\)/',
            $popup,
            'init() fetches the gift list again, so every shopper on the cart page pays for the '
            . 'popup whether or not they open it — and Hyvä re-inits the component every ~2.5s'
        );
        self::assertDoesNotMatchRegularExpression(
            '/x-if="gifts\.length > 0"/',
            $popup,
            'the trigger button is gated on the gift list again, which is what forced the eager '
            . 'fetch: the component had to load every gift just to decide whether to draw a button'
        );
        self::assertStringContainsString('openDialog', $popup, 'no open handler to fetch lazily from');
    }

    /**
     * Every label the JavaScript reads must be one the PHP actually emits.
     *
     * DERIVED FROM THE FILE, BOTH WAYS, so neither list can drift: adding
     * `freeGiftPopupLabels.somethingNew` to the markup without adding the key fails here
     * rather than rendering `undefined` to a shopper. The fix added exactly such
     * a reference (`noGifts`), which is how this gap became worth closing.
     */
    public function testEveryLabelTheJavaScriptReadsIsEmittedByThePhp(): void
    {
        $popup = self::popup();

        preg_match_all('/freeGiftPopupLabels\.([A-Za-z0-9_]+)/', $popup, $used);
        $referenced = array_values(array_unique($used[1]));
        self::assertNotEmpty($referenced, 'CONTROL: no label references found, so nothing was checked');

        preg_match_all("/'([A-Za-z0-9_]+)'\s*=>\s*\(string\) __\(/", $popup, $emitted);
        $declared = $emitted[1];
        self::assertNotEmpty($declared, 'CONTROL: no label keys found in the PHP array');

        foreach ($referenced as $key) {
            self::assertContains(
                $key,
                $declared,
                "the template reads freeGiftPopupLabels.{$key} but the PHP emits no '{$key}' key, "
                . 'so a shopper sees undefined'
            );
        }
    }

    /**
     * The body of a `name: function (...) { ... }` member, brace-balanced.
     *
     * @param string $source
     * @param string $name
     * @return string
     */
    private static function bodyOf(string $source, string $name): string
    {
        if (!preg_match('/' . preg_quote($name, '/') . ':\s*function\s*\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $i = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $start = $i;
        $len = strlen($source);
        while ($i < $len && $depth > 0) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
            }
            $i++;
        }
        return substr($source, $start, $i - $start - 1);
    }

    /** Markup with `//` and `/* *&#47;` comment bodies removed. */
    private static function withoutComments(string $markup): string
    {
        $markup = (string) preg_replace('~/\*.*?\*/~s', '', $markup);
        return (string) preg_replace('~^\s*//.*$~m', '', $markup);
    }
}
