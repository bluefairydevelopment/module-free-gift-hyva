<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * A LAYOUT FILE IN THIS PACKAGE THAT IS NOT `hyva_`-PREFIXED APPLIES
 * ON EVERY THEME, INCLUDING LUMA.
 *
 * Hyvä's `AddLayoutHandles` observer adds a `hyva_`-prefixed twin for each
 * handle the page already carries, and only when a Hyvä theme is active. So
 * `hyva_checkout_cart_index.xml` loads under Hyvä and nowhere else, while
 * `checkout_cart_index.xml` loads under EVERY theme - this package's whole
 * reason for existing is to be theme-specific, and an unprefixed filename
 * silently makes it global.
 *
 * WHY THAT IS WORSE HERE THAN "SOME EXTRA MARKUP". These files do not only add;
 * they REMOVE. `hyva_checkout_cart_index.xml` removes
 * `bluefairy.freegift.cart.progressbar`, `...giftpopup` and `...countdown` -
 * the base module's own Luma blocks - and replaces them with Alpine.js
 * templates. Unprefixed, a Luma shopper therefore loses the working progress
 * bar, gift popup and countdown and is served Alpine markup with no Alpine to
 * drive it. The base module appears broken, on a theme this package was never
 * meant to touch, and only because its companion is installed.
 *
 * NOTHING ELSE IN THIS REPOSITORY COULD HAVE SEEN IT. Until this test existed,
 * `FreeGiftHyva` shipped ZERO test files - 52 in `FreeGift`, 3 in
 * `FreeGiftGraphQl`, 0 here - and `phpunit.xml.dist` did not list its directory
 * at all, so there was no suite for such a check to fail in. The filename is
 * valid, the XML validates, and Magento reports nothing: a layout file that
 * applies too widely is indistinguishable from one that applies correctly
 * except by reading the name or by loading Luma and looking.
 */
class EveryLayoutFileIsHyvaPrefixedTest extends TestCase
{
    private const LAYOUT_DIR = '/view/frontend/layout';

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] absolute paths */
    private static function layoutFiles(): array
    {
        $dir = self::packageRoot() . self::LAYOUT_DIR;
        self::assertDirectoryExists($dir, 'this package ships no layout directory');

        return array_values(array_filter((array) glob($dir . '/*.xml'), 'is_string'));
    }

    public function testEveryLayoutFileIsPrefixed(): void
    {
        $files = self::layoutFiles();

        // The control. An empty sweep makes "none of them is unprefixed"
        // vacuously true, and a moved directory is exactly how that happens.
        self::assertNotEmpty(
            $files,
            'no layout XML was found in ' . self::LAYOUT_DIR . '. This package exists to override '
            . 'layout for one theme, so an empty result means this sweep has lost its subject '
            . 'rather than that there is nothing to check. Retarget it; do not delete it.'
        );

        $unprefixed = [];
        foreach ($files as $file) {
            if (!str_starts_with(basename($file), 'hyva_')) {
                $unprefixed[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $unprefixed,
            sprintf(
                "these layout files are not hyva_-prefixed, so they apply on EVERY theme:\n  %s\n\n"
                . "On Luma that strips the base module's progress bar, gift popup and countdown "
                . "and replaces them with Alpine templates that have no Alpine, so the BASE module "
                . "appears broken purely because this companion is installed. Rename the file; do "
                . "not add a theme check inside it. Files examined: %d.",
                implode("\n  ", $unprefixed),
                count($files)
            )
        );
    }

    /**
     * The removals are the reason the prefix matters, so assert they are really
     * there. If a future edit drops them, the prefix rule above stops being
     * load-bearing and this test would keep passing while guarding nothing.
     */
    public function testTheseFilesRemoveBaseBlocksAndThereforeMustNotApplyToLuma(): void
    {
        $removals = 0;
        foreach (self::layoutFiles() as $file) {
            $xml = (string) file_get_contents($file);
            $removals += preg_match_all('/<referenceBlock[^>]*remove="true"/', $xml);
        }

        self::assertGreaterThan(
            0,
            $removals,
            'no layout file in this package removes a base block any more. That may be fine - but '
            . 'the prefix rule above is justified in its docblock by these removals breaking Luma, '
            . 'and that justification is now stale. Re-read it and re-state why the prefix is '
            . 'required, rather than leaving a rule whose stated reason no longer holds.'
        );
    }
}
