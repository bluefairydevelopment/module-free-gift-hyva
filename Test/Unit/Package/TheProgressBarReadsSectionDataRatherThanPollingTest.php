<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Package;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Held so a reader cannot reintroduce the poll.
 *
 * The popup defers its fetch to first open. The bar has no open — the bar IS the
 * rendering — and Alpine discards the component's state every time Hyva replaces the cart DOM
 * (about every 2.5s), so the data had to come from outside the component: Magento's customer
 * section, dispatched on Hyva's `private-content-loaded` event.
 *
 * With the old timer, in 10-second windows on the same cart page:
 *   bluefairy_freegift/cart/barstate/   module ON 4   module OFF 0
 *
 * TWO WAYS THIS CAN BREAK SILENTLY, AND BOTH ARE ASSERTED HERE:
 *   1. a fetch creeps back into init(), restoring the 2.5s cadence; and
 *   2. the section name drifts apart from the base package's di.xml registration, which
 *      empties the bar with no error anywhere — a wiring gap.
 */
class TheProgressBarReadsSectionDataRatherThanPollingTest extends TestCase
{
    private const SECTION_NAME = 'bluefairy_freegift_bars';

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function template(): string
    {
        $path = self::packageRoot() . '/view/frontend/templates/cart/progress-bar.phtml';
        self::assertFileExists($path, 'the progress-bar template is missing');

        return (string) file_get_contents($path);
    }

    /**
     * The base package's frontend di.xml, wherever this package is installed from.
     *
     * Checked in BOTH layouts rather than skipping when one is absent: a skip here would
     * quietly stop testing the one thing this file exists for the moment the packaging
     * changed.
     */
    private static function baseDiXmlPath(): string
    {
        $candidates = [
            dirname(self::packageRoot()) . '/FreeGift/etc/frontend/di.xml',
            self::packageRoot() . '/vendor/bluefairy/module-free-gift/etc/frontend/di.xml',
            dirname(self::packageRoot(), 3) . '/bluefairy/module-free-gift/etc/frontend/di.xml',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        self::fail(
            'the base package could not be located, so the section name could not be '
            . 'cross-checked. Looked in: ' . implode(', ', $candidates)
        );
    }

    // ------------------------------------------------------------------
    // The poll must not come back
    // ------------------------------------------------------------------

    public function testInitDoesNotFetchBecauseInitRunsEveryTwoAndAHalfSeconds(): void
    {
        $markup = self::withoutComments(self::template());

        self::assertDoesNotMatchRegularExpression(
            '/init:\s*function\s*\([^)]*\)\s*\{[^}]*fetch\s*\(/',
            $markup,
            'init() fetches again. Hyva re-creates this component every time it replaces the '
            . 'cart DOM, so anything fetched from init() is fetched on that cadence — which is '
            . 'the entire defect.'
        );
    }

    /** CONTROL: the predicate above must match a fetch in init() when one is really there. */
    public function testTheInitFetchScanCanFindAFetchInInit(): void
    {
        $specimen = 'return { init: function () { this.fetchBars(); fetch(url); }, };';
        self::assertMatchesRegularExpression(
            '/init:\s*function\s*\([^)]*\)\s*\{[^}]*fetch\s*\(/',
            self::withoutComments($specimen),
            'CONTROL: the scan cannot see a fetch that is really inside init()'
        );
    }

    public function testNothingPollsOnATimer(): void
    {
        $markup = self::withoutComments(self::template());

        foreach (['setInterval', 'setTimeout'] as $timer) {
            self::assertStringNotContainsString(
                $timer,
                $markup,
                "the bar uses {$timer} again. The fix replaced a timer with an event; a timer "
                . 'reintroduces the cost this ticket measured.'
            );
        }
    }

    /**
     * The component must listen for the section payload, or it renders nothing at all — the
     * failure mode of removing the fetch without wiring the replacement.
     */
    public function testTheBarListensForTheSectionPayload(): void
    {
        $markup = self::template();

        self::assertStringContainsString(
            '@private-content-loaded.window',
            $markup,
            'nothing listens for Hyva section data, so the bar has no source of truth at all'
        );
        self::assertStringContainsString('onPrivateContent', $markup);
    }

    // ------------------------------------------------------------------
    // The name must match the base package's registration
    // ------------------------------------------------------------------

    public function testTheSectionNameMatchesTheBasePackagesRegistration(): void
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->load(self::baseDiXmlPath()), 'the base di.xml is not parseable XML');
        $xpath = new DOMXPath($dom);

        $items = $xpath->query(
            '//type[@name="Magento\Customer\CustomerData\SectionPoolInterface"]'
            . '/arguments/argument[@name="sectionSourceMap"]/item'
        );
        self::assertNotNull($items);
        self::assertGreaterThan(
            0,
            $items->length,
            'CONTROL: the base package registers no customer sections at all, so this '
            . 'comparison had nothing to compare against'
        );

        $registered = [];
        foreach ($items as $item) {
            $registered[] = $item->getAttribute('name');
        }

        self::assertContains(
            self::SECTION_NAME,
            $registered,
            'this template reads "' . self::SECTION_NAME . '" out of the section payload, but the '
            . 'base package registers none by that name. The bar would be permanently empty and '
            . 'nothing would log an error. Registered: ' . implode(', ', $registered)
        );

        self::assertStringContainsString(
            "'" . self::SECTION_NAME . "'",
            self::template(),
            'the template no longer names the section it reads'
        );
    }

    /**
     * The one-shot fallback must stay one-shot.
     *
     * A shopper whose mage-cache-storage predates the section is served that cache WITHOUT a
     * fetch, so the template needs a way to fill a missing key. Ungated, that path is a fetch
     * on every re-init — the original defect wearing a different name.
     */
    public function testTheStaleCacheFallbackIsGuardedSoItCannotBecomeThePollAgain(): void
    {
        $markup = self::withoutComments(self::template());

        self::assertStringContainsString(
            'fallbackAttempted',
            $markup,
            'the fallback fetch has no guard, so a shopper whose section payload never arrives '
            . 'fetches on every DOM replacement — 4 requests per 10s, exactly as before'
        );
        $body = self::bodyOf($markup, 'fetchBarsOnce');
        self::assertNotSame('', $body, 'no fetchBarsOnce() handler found');
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*this\.fallbackAttempted\s*\)\s*\{\s*return/',
            $body,
            'fetchBarsOnce() does not return early on its own guard, so the guard is decorative'
        );
        self::assertStringContainsString(
            'this.fallbackAttempted = true',
            $body,
            'the guard is never set, so it can never stop a second attempt'
        );
    }

    /**
     * THE DEFECT THE RIG FOUND IN THE CURE ITSELF.
     *
     * A component that only learns from an event is BLANK whenever it is re-created between
     * events — and Hyva re-creates it every time it replaces the cart DOM. Measured on the
     * rig: barsRendered went 2 -> 0 across a run of cart updates, with the component still in
     * the DOM, its bars array empty and its fallback flag never set, so it had received no
     * event at all since being built. The previous code hid this by fetching on every init.
     *
     * init() must therefore seed from the same payload the event carries — a synchronous
     * localStorage read, no request — or the bar is empty for exactly the shoppers whose
     * carts are busiest.
     */
    public function testInitSeedsFromStorageOrTheBarIsBlankAfterEveryCartDomReplacement(): void
    {
        $markup = self::withoutComments(self::template());

        $init = self::bodyOf($markup, 'init');
        self::assertNotSame('', $init, 'no init() handler found');
        self::assertStringContainsString(
            'seedFromStorage',
            $init,
            'init() does not seed from storage, so a component re-created between events '
            . 'renders an empty bar until the shopper does something else'
        );

        $seed = self::bodyOf($markup, 'seedFromStorage');
        self::assertNotSame('', $seed, 'no seedFromStorage() handler found');
        self::assertStringContainsString(
            'mage-cache-storage',
            $seed,
            'the seed does not read Hyva section storage, so it cannot have the bar data'
        );
        self::assertStringNotContainsString(
            'fetch',
            $seed,
            'the seed makes a network request. The whole point is that it does not: it reads '
            . 'the payload the event would have carried, synchronously, at zero cost.'
        );
    }

    /**
     * Storage access must be guarded. localStorage THROWS on access in some contexts, not
     * merely return null, and an exception in init() takes the whole Alpine component down —
     * a blank bar plus a console error, for a shopper whose only sin is a private window.
     */
    public function testTheStorageReadIsGuardedBecauseLocalStorageCanThrowRatherThanReturnNull(): void
    {
        $seed = self::bodyOf(self::withoutComments(self::template()), 'seedFromStorage');
        self::assertNotSame('', $seed);

        self::assertMatchesRegularExpression(
            '/try\s*\{[^}]*localStorage[^}]*\}\s*catch/s',
            $seed,
            'the localStorage read is not inside a try/catch'
        );
        self::assertMatchesRegularExpression(
            '/JSON\.parse/',
            $seed,
            'nothing parses the stored payload'
        );
        self::assertSame(
            2,
            substr_count($seed, 'catch'),
            'both the storage READ and the JSON PARSE must be guarded: a corrupt '
            . 'mage-cache-storage value throws on parse, not on read'
        );
    }

    /**
     * An EMPTY bars list is a real answer — the shopper qualifies for nothing — and must not
     * trigger the fallback. Only an ABSENT key means "this payload predates the section".
     * Confusing the two puts a fetch back on every cart page with no applicable rule, which
     * is most of them.
     */
    public function testAnEmptyBarListIsTreatedAsAnAnswerRatherThanAsAMissingPayload(): void
    {
        $body = self::bodyOf(self::withoutComments(self::template()), 'onPrivateContent');
        self::assertNotSame('', $body, 'no onPrivateContent() handler found');

        self::assertMatchesRegularExpression(
            '/Array\.isArray\(\s*section\.bars\s*\)/',
            $body,
            'the handler does not distinguish an empty bars array from an absent section. An '
            . 'empty list is a valid answer; only a missing key justifies a network call.'
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The body of a `name: function (...) { ... }` member, brace-balanced.
     *
     * @param string $source
     * @param string $name
     * @return string
     */
    private static function bodyOf(string $source, string $name): string
    {
        $pattern = '/' . preg_quote($name, '/') . ':\s*function\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
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

    /**
     * Markup with line and block comment bodies removed.
     *
     * @param string $markup
     * @return string
     */
    private static function withoutComments(string $markup): string
    {
        $markup = (string) preg_replace('#/\*.*?\*/#s', '', $markup);
        $markup = (string) preg_replace('/^\s*\/\/.*$/m', '', $markup);

        return (string) preg_replace('/<!--.*?-->/s', '', $markup);
    }
}
