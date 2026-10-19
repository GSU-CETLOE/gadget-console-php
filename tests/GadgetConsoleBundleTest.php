<?php

declare(strict_types=1);

namespace Gadget\Console\Tests;

use Gadget\Console\DependencyInjection\GadgetConsoleExtension;
use Gadget\Console\GadgetConsoleBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class GadgetConsoleBundleTest extends TestCase
{
    public function testIsASymfonyBundle(): void
    {
        self::assertInstanceOf(Bundle::class, new GadgetConsoleBundle());
    }


    public function testExposesTheGadgetConsoleExtension(): void
    {
        $extension = (new GadgetConsoleBundle())->getContainerExtension();

        self::assertInstanceOf(GadgetConsoleExtension::class, $extension);
        self::assertSame('gadget_console', $extension->getAlias());
    }
}
