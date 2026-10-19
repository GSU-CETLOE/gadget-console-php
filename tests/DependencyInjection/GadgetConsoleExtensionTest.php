<?php

declare(strict_types=1);

namespace Gadget\Console\Tests\DependencyInjection;

use Gadget\Console\Command\Command;
use Gadget\Console\Command\ShellCommand;
use Gadget\Console\DependencyInjection\GadgetConsoleExtension;
use Gadget\Console\Event\ConsoleEventHandler;
use Gadget\Log\Monolog\Processor\ConsoleCommandProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GadgetConsoleExtensionTest extends TestCase
{
    private static function loadedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new GadgetConsoleExtension())->load([], $container);
        return $container;
    }


    public function testRegistersTheEventHandlerAsAnAutowiredService(): void
    {
        $container = self::loadedContainer();

        self::assertTrue($container->hasDefinition(ConsoleEventHandler::class));
        $definition = $container->getDefinition(ConsoleEventHandler::class);
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
    }


    /**
     * @return \Generator<string, array{class-string}>
     */
    public static function providesAbstractCommandClasses(): \Generator
    {
        yield Command::class => [Command::class];
        yield ShellCommand::class => [ShellCommand::class];
    }


    /**
     * The abstract command bases are meant to be extended by the application,
     * not served from the container.
     *
     * @param class-string $class
     */
    #[DataProvider('providesAbstractCommandClasses')]
    public function testDoesNotRegisterTheAbstractCommandBases(string $class): void
    {
        self::assertFalse(self::loadedContainer()->hasDefinition($class), "$class should not be registered");
    }


    public function testCompiledContainerBuildsTheEventHandlerWithTheLogProcessor(): void
    {
        $container = self::loadedContainer();
        $container->register(ConsoleCommandProcessor::class)->setAutowired(true);
        $container->getDefinition(ConsoleEventHandler::class)->setPublic(true);

        $container->compile();

        self::assertInstanceOf(ConsoleEventHandler::class, $container->get(ConsoleEventHandler::class));
    }
}
