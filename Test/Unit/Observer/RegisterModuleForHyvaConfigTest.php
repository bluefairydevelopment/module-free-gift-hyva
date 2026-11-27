<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 */

declare(strict_types=1);

namespace BlueFairy\FreeGiftHyva\Test\Unit\Observer;

use BlueFairy\FreeGiftHyva\Observer\RegisterModuleForHyvaConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * THE ONLY PHP CLASS THIS PACKAGE SHIPS, AND IT HAD NO TEST.
 *
 * FreeGiftHyva shipped zero tests until 2026-09-19; the two added then read layout
 * files off disk, so this observer - the thing that makes Hyvä's Tailwind build see
 * this module's templates at all - still had nothing exercising it. If it stops
 * appending, the utility classes used only in this package are purged from the
 * compiled CSS and the cart UI loses its styling on a Hyvä store, silently: no error,
 * no exception, a page that renders and looks wrong.
 *
 * ORDER MATTERS INSIDE testTheBranchesThatDependOnBp. `BP` is Magento's base-path
 * constant, defined by app/bootstrap.php and NOT by this suite - measured: undefined
 * under Test/bootstrap.php. Defining it is irreversible for the process, so the
 * "BP absent" branch is asserted FIRST, in the same method, before the define. Split
 * across two test methods this would depend on PHPUnit's execution order.
 */
class RegisterModuleForHyvaConfigTest extends TestCase
{
    private const MODULE = 'BlueFairy_FreeGiftHyva';

    public function testItDoesNothingWhenTheEventCarriesNoConfig(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->expects(self::never())->method('getPath');

        $event = new Observer();
        $event->setData('config', null);

        (new RegisterModuleForHyvaConfig($registrar))->execute($event);

        // The assertion is the never() above; this states the postcondition in the
        // same terms as the other cases.
        self::assertNull($event->getData('config'));
    }

    public function testItDoesNothingWhenThisModuleIsNotRegistered(): void
    {
        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')->with(ComponentRegistrar::MODULE, self::MODULE)->willReturn(null);

        $config = new DataObject(['extensions' => [['src' => 'vendor/someone/else']]]);
        $event = new Observer();
        $event->setData('config', $config);

        (new RegisterModuleForHyvaConfig($registrar))->execute($event);

        self::assertSame(
            [['src' => 'vendor/someone/else']],
            $config->getData('extensions'),
            'an unregistered module still changed the extension list'
        );
    }

    public function testTheBranchesThatDependOnBp(): void
    {
        $path = '/fake/magento/vendor/bluefairy/module-free-gift-hyva';

        $registrar = $this->createMock(ComponentRegistrar::class);
        $registrar->method('getPath')->with(ComponentRegistrar::MODULE, self::MODULE)->willReturn($path);

        // 1. BP ABSENT. Asserted before the define below, and this is why the three
        //    cases share one method.
        self::assertFalse(
            defined('BP'),
            'BP was already defined before this test ran, so the no-BP branch cannot be exercised '
            . 'and this method would report on two of its three cases'
        );

        $config = new DataObject();
        $event = new Observer();
        $event->setData('config', $config);
        (new RegisterModuleForHyvaConfig($registrar))->execute($event);
        self::assertNull(
            $config->getData('extensions'),
            'without BP there is no root to make the path relative to, and a wrong relative path '
            . 'is worse than none - the observer must leave the list alone'
        );

        define('BP', '/fake/magento');

        // 2. BP PRESENT, nothing registered yet. The path must arrive ROOT-RELATIVE:
        //    Hyvä resolves it from the Magento root, so an absolute one scans nothing.
        $config = new DataObject();
        $event = new Observer();
        $event->setData('config', $config);
        (new RegisterModuleForHyvaConfig($registrar))->execute($event);
        self::assertSame(
            [['src' => 'vendor/bluefairy/module-free-gift-hyva']],
            $config->getData('extensions'),
            'this module did not reach the Hyvä extension list, or did not reach it root-relative'
        );

        // 3. BP PRESENT, another extension already registered. APPEND, never replace -
        //    this observer runs alongside Hyva_Theme's own and every other module's.
        $config = new DataObject(['extensions' => [['src' => 'vendor/someone/else']]]);
        $event = new Observer();
        $event->setData('config', $config);
        (new RegisterModuleForHyvaConfig($registrar))->execute($event);
        self::assertSame(
            [
                ['src' => 'vendor/someone/else'],
                ['src' => 'vendor/bluefairy/module-free-gift-hyva'],
            ],
            $config->getData('extensions'),
            'the observer replaced the extension list instead of appending to it, which would '
            . 'unregister every other module that had already run'
        );
    }
}
