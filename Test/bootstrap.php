<?php

/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * THIS SUITE USED TO TEST THE INSTALLED COPY, NOT THIS REPOSITORY.
 *
 * Magento's unit bootstrap registers its own autoloader with prepend = true, so
 * `BlueFairy\...` resolved to whatever sits in the Magento install's
 * vendor/bluefairy/ - a DIFFERENT COPY, with a different inode. Proven with a
 * sentinel rather than inferred: a method added to this repository's
 * Observer/RegisterModuleForHyvaConfig.php was ABSENT from the class the suite loaded, and
 * ReflectionClass::getFileName() named the vendor path.
 *
 * So every green this suite has ever produced was a statement about the
 * DEPLOYED package. An edit here could not fail it, and a defect here could not
 * be caught by it.
 *
 * The cure is to register this package's own PSR-4 mapping AFTER Magento's
 * bootstrap and with prepend = true, so that it wins. Magento's autoloader is
 * still there for framework classes; only this one namespace is claimed.
 *
 * It also removes the hardcoded path the phpunit.xml carried, which pointed
 * either four directories up (assuming the package sat inside a Magento install
 * at app/code) or at one developer's home directory.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: '';
if ($magentoRoot === '' || !is_file($magentoRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "MAGENTO_ROOT must point at a Magento installation whose vendor/ carries the framework:\n"
        . "    MAGENTO_ROOT=/path/to/magento /path/to/magento/vendor/bin/phpunit -c phpunit.xml.dist\n"
    );
    // phpcs:ignore Magento2.Security.LanguageConstruct -- a test bootstrap that refuses to run exits non-zero; the exit is the refusal
    exit(1);
}

// vendor/autoload.php, NEVER app/bootstrap.php and never the unit-test
// bootstrap. Magento's app/bootstrap.php calls stream_wrapper_unregister('phar'),
// and phpstan runs FROM a .phar - it then dies inside its own error renderer
// naming a class unrelated to anything. The unit bootstrap is no better for
// static analysis: it does not give the analyser a composer loader it can use
// to find Magento's own classes, so phpstan reports real methods and constants
// as undefined. Measured here: it called
// Magento\Framework\Controller\ResultFactory::TYPE_JSON undefined, while
// reflection through this same bootstrap shows the constant present.
require $magentoRoot . '/vendor/autoload.php';

// BP IS DELIBERATELY NOT DEFINED HERE, and must not be added.
//
// This bootstrap was adapted from module-recipes-hyva's, which does define it. That
// is right there and wrong here: RegisterModuleForHyvaConfigTest asserts the branch
// of the observer that runs when BP is ABSENT, and it does so first in the method
// because defining BP is irreversible for the process. Its docblock records the fact
// as measured - "undefined under Test/bootstrap.php" - and the monorepo bootstrap
// this package came from never defined it either.
//
// Defining it here turned that test red with "Failed asserting that true is false",
// which is what a copied-in assumption looks like when the destination had written
// down the opposite.

// MAGENTO'S UNIT-TEST FRAMEWORK IS DELIBERATELY NOT LOADED, and must not be added.
//
// This is the second assumption carried in from module-recipes-hyva's bootstrap that
// is right there and wrong here. That package needs the framework - its comment says
// 274 tests error without it, because they use Magento's TestFramework ObjectManager
// helper. None of this package's 42 tests do, and the monorepo bootstrap it came from
// never loaded it either.
//
// Loading it matters because that bootstrap reaches app/bootstrap.php, which DEFINES
// BP. RegisterModuleForHyvaConfigTest asserts the branch of the observer that runs
// when BP is absent, and records that absence as a measured fact. So requiring the
// framework here turned that test red with "Failed asserting that true is false" -
// and it stayed red after the explicit define('BP') was removed, because this was the
// real source.


$packageRoot = dirname(__DIR__);
spl_autoload_register(
    static function (string $class) use ($packageRoot): void {
        $prefix = 'BlueFairy\\FreeGiftHyva\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $packageRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    },
    true,
    true // PREPEND: Magento's autoloader also prepends, so this must be registered after it AND prepend.
);
