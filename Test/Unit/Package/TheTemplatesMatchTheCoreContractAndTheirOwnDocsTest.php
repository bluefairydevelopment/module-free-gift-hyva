<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Package;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * PORTED from module-free-gift, where these assertions ran from the base package's
 * suite because both packages were in one tree. This package left that repository on
 * 2026-11-27 and they came with it.
 *
 * They are in two kinds, and the distinction is the point.
 *
 * THIS PACKAGE'S OWN FILES. The popup template's limit handling, and the claims its
 * FEATURES.md and README make about what the templates do. Nothing outside this
 * repository is needed to check them, and nothing outside it should be: a package that
 * cannot verify its own documentation is a package whose documentation drifts.
 *
 * THE CONTRACT WITH THE CORE. The popup consumes a payload the core's ViewModel and
 * controller produce - `gifts_taken` and `limit_reached`. In the monorepo, one test read
 * both sides out of one tree. Split, each side proves its own half: the core asserts the
 * payload it PRODUCES (TheLimitFlagsComeFromTheViewModelPayload, TheAddEndpointAnswers-
 * WithTheCount, both still in module-free-gift), and this asserts the keys it CONSUMES.
 * That is stronger than the monorepo arrangement, not weaker - each repository is
 * answerable for the side of the contract it ships, and a change to either side fails in
 * the repository that made it.
 *
 * NO DEV DEPENDENCY WAS ADDED. bluefairy/module-free-gift is a hard `require` of this
 * package, so wherever this package is installed the core is in the Magento root's
 * vendor/. Putting it in require-dev would break `composer install` in this public
 * repository for anyone without Private Packagist credentials, since the core is not on
 * public Packagist.
 */
#[CoversNothing]
class TheTemplatesMatchTheCoreContractAndTheirOwnDocsTest extends TestCase
{
    /** The payload keys the core produces and this package's popup must read. */
    private const CONTRACT_KEYS = ['gifts_taken', 'limit_reached'];

    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path, $relative . ' is not in this package');

        return (string) file_get_contents($path);
    }

    // ------------------------------------------------- this package's own files

    public function testTheTemplateSkipsARuleAtItsLimitAndWithdrawsTheTriggerAfterTheLastAllowedAdd(): void
    {
        $t = self::read('view/frontend/templates/cart/gift-popup.phtml');

        self::assertMatchesRegularExpression("/if \\(\\\$rule\\['limit_reached'\\]\\) \\{\\s*continue;/", $t);
        self::assertStringContainsString("<?= (int) \$rule['gifts_taken'] ?>", $t);
        // Strict CSP: the attribute names a method, and the method holds the negation.
        self::assertStringContainsString('x-show="triggerVisible"', $t);
        self::assertStringContainsString('triggerVisible: function () { return !this.limitReached; }', $t);
        self::assertMatchesRegularExpression('/self\\.giftsTaken = data\\.gifts_taken;/', $t);
        self::assertMatchesRegularExpression('/if \\(data\\.limit_reached === true\\) \\{\\s*self\\.limitReached = true;/', $t);
        // The page no longer counts adds itself, and a component starts open: the server
        // renders none for a rule at its limit.
        self::assertStringNotContainsString('self.giftsTaken += 1', $t);
        self::assertMatchesRegularExpression('/^\\s*limitReached: false,$/m', $t);
    }

    public function testTheDocsMakeNoClaimTheTemplatesDoNotSupport(): void
    {
        $features = self::read('FEATURES.md');
        foreach (['smooth animations', 'responsive grid', 'Mobile responsive', 'Responsive modal'] as $claim) {
            self::assertStringNotContainsString($claim, $features, 'FEATURES.md claims ' . $claim);
        }

        // The templates really carry no breakpoint class, grid or modal transition the
        // claims would rest on.
        $checked = 0;
        foreach (['cart/progress-bar.phtml', 'widget/countdown.phtml', 'cart/gift-popup.phtml'] as $template) {
            $text = self::read('view/frontend/templates/' . $template);
            self::assertSame(0, preg_match('/\b(?:sm|md|lg|xl):[a-z]/', $text), $template . ' has breakpoint classes');
            $checked++;
        }
        self::assertSame(3, $checked, 'expected all three templates the claims are about');

        self::assertSame(
            0,
            preg_match('/\bgrid-cols-|\bgrid\b(?= )/', self::read('view/frontend/templates/cart/gift-popup.phtml'))
        );

        $readme = self::read('README.md');
        self::assertStringNotContainsString('tailwind.config.js', $readme);
        self::assertStringNotContainsString('app/design/frontend/Hyva/default', $readme);

        $composer = json_decode(self::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $constraint = $composer['require']['hyva-themes/magento2-theme-module'];
        self::assertStringContainsString('hyva-themes/magento2-theme-module ' . $constraint, $readme);
    }

    public function testTheShippedCommentsAboutThePopupMatchTheCode(): void
    {
        $popup = self::read('view/frontend/templates/cart/gift-popup.phtml');

        self::assertStringContainsString('if (!this.fetched) { this.fetchGifts(); }', $popup);
        self::assertStringNotContainsString('gifts from the giftpopup endpoint on init', $popup);
        self::assertStringNotContainsString('auto-opens', $popup);
    }

    public function testTheReadmeDocumentsTheGiftCountTheTemplateRenders(): void
    {
        // The core's README says how many gift lines a shopper may take; this package's
        // says what the template does with the server's count. Both must name the field.
        self::assertStringContainsString('gifts_taken', self::read('README.md'));
    }

    // ------------------------------------------------- the contract with the core

    public function testThePopupConsumesExactlyThePayloadKeysTheCoreProduces(): void
    {
        $popup = self::read('view/frontend/templates/cart/gift-popup.phtml');

        foreach (self::CONTRACT_KEYS as $key) {
            self::assertStringContainsString(
                $key,
                $popup,
                "the popup does not read '{$key}', which the core's GiftPopup view model and "
                . 'GiftAdd controller both put in their payload. The core asserts it SENDS these; '
                . 'this asserts this package READS them.'
            );
        }
    }

    public function testTheCoreStillSendsThePayloadThisPackageReads(): void
    {
        $core = self::coreRoot();

        $viewModel = (string) file_get_contents($core . '/ViewModel/Cart/GiftPopup.php');
        self::assertNotSame('', $viewModel, "could not read the core's GiftPopup view model");
        self::assertStringContainsString("'gifts_taken' => \$held", $viewModel);
        self::assertStringContainsString("'limit_reached' => \$held >= max(1, (int) \$giftQty)", $viewModel);

        $controller = (string) file_get_contents($core . '/Controller/Cart/GiftAdd.php');
        self::assertNotSame('', $controller, "could not read the core's GiftAdd controller");
        self::assertStringContainsString("'gifts_taken' => \$giftsTaken", $controller);
        self::assertStringContainsString(
            "'limit_reached' => \$giftsTaken >= max(1, (int) \$rule->getGiftQty())",
            $controller
        );
    }

    /**
     * The installed core's package root.
     *
     * NOT A SKIP when it is missing. A skipped check whose reason never expires is how a
     * suite goes quiet: this package hard-requires the core, so its absence means the
     * installation is broken and that is worth failing over. In the monorepo the
     * equivalent read a sibling directory out of one tree; here it reads the installed
     * copy, at the version this package's constraint admits, which is the copy a merchant
     * actually runs.
     */
    private static function coreRoot(): string
    {
        $magento = getenv('MAGENTO_ROOT') ?: '';
        self::assertNotSame(
            '',
            $magento,
            'MAGENTO_ROOT is not set, so the installed core cannot be found. Run: '
            . 'MAGENTO_ROOT=/path/to/magento /path/to/magento/vendor/bin/phpunit -c phpunit.xml.dist'
        );

        $root = $magento . '/vendor/bluefairy/module-free-gift';
        self::assertDirectoryExists(
            $root,
            'bluefairy/module-free-gift is not installed at ' . $root . '. This package declares it '
            . 'as a hard require (^1.2), so it should be there. It is deliberately NOT a '
            . 'require-dev of this repository: the core is not on public Packagist, so that would '
            . 'break composer install here for anyone without Private Packagist credentials.'
        );

        return $root;
    }
}
