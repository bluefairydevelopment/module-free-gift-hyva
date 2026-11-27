<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Package;

use Composer\Semver\Semver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * this package requires the base release
 * whose behaviour its README and CHANGELOG describe.
 *
 * Its documentation states what the base package does: the countdown ends at the end of its day in
 * the store's time zone, a page with it carries the countdown cache tag, the Countdown End Date
 * takes precedence over the To Date, and the product badge block is the base's `GiftLabel`. None of
 * that is in a released base (1.0.0, 1.0.1, 1.0.2, 1.1.0): it arrives in the base's next release,
 * 1.2.0. A `^1.0` requirement let a store install this package on 1.0.2 or 1.1.0 and be told, by
 * the README, a countdown behaviour it would not have. The requirement is `^1.2`, and this test
 * reads the base's own tree for each fact so a base that moves one away fails here.
 */
#[CoversNothing]
class TheBaseRequirementShipsWhatThisPackageDocumentsTest extends TestCase
{
    /** Base releases without the documented behaviour. */
    private const WITHOUT = ['1.0.0', '1.0.1', '1.0.2', '1.1.0'];

    public function testTheBaseRequirementRefusesEveryReleaseWithoutTheDocumentedBehaviour(): void
    {
        $constraint = $this->require('bluefairy/module-free-gift');

        foreach (self::WITHOUT as $release) {
            self::assertFalse(Semver::satisfies($release, $constraint), "\"{$constraint}\" admits base {$release}");
        }
        self::assertTrue(Semver::satisfies('1.2.0', $constraint), "\"{$constraint}\" refuses base 1.2.0");
        self::assertFalse(Semver::satisfies('2.0.0', $constraint), "\"{$constraint}\" admits the next major");
    }

    /**
     * The base's own tree carries each documented behaviour. It does NOT list the base's released
     * versions or demand an Unreleased heading: when the base is tagged 1.2.0 its CHANGELOG
     * heading changes from `## Unreleased` to `## 1.2.0`, and this test must stay green on that
     * correct release. The top heading may be Unreleased or a 1.2.x.
     */
    public function testTheBasesTreeCarriesEachDocumentedFactAndItsChangelogOpensOnItsNextRelease(): void
    {
        $changelog = (string) file_get_contents($this->root() . '/FreeGift/CHANGELOG.md');

        self::assertMatchesRegularExpression(
            '/\A# [^\n]*\n+## (Unreleased|1\.2\.\d+)\b/',
            $changelog,
            'the base\'s CHANGELOG opens on Unreleased or a 1.2.x release'
        );

        $facts = [
            'FreeGift/Block/Product/GiftLabel.php' => 'class GiftLabel',
            'FreeGift/Model/Cache/RulePageTags.php' => 'COUNTDOWN_TAG',
            'FreeGift/Model/Timer/CountdownProvider.php' => 'function toStoreTimestamp',
        ];
        foreach ($facts as $file => $needle) {
            self::assertStringContainsString($needle, (string) file_get_contents($this->root() . '/' . $file), $file);
        }
    }

    private function require(string $name): string
    {
        $manifest = json_decode((string) file_get_contents($this->root() . '/FreeGiftHyva/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($manifest['require'][$name] ?? null, "FreeGiftHyva/composer.json requires no {$name}");

        return $manifest['require'][$name];
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
