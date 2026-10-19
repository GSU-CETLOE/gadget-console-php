<?php

declare(strict_types=1);

namespace Gadget\Console\Tests\Command;

use Gadget\Console\Command\Command;
use Gadget\Log\LoggerProxyInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandTest extends TestCase
{
    /**
     * A command that logs one line through its own proxy when it runs.
     *
     * @return Command
     */
    private static function loggingCommand(): Command
    {
        return new class ('app:noop') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->info('executed');
                return self::SUCCESS;
            }
        };
    }


    public function testIsASymfonyConsoleCommand(): void
    {
        self::assertInstanceOf(BaseCommand::class, self::loggingCommand());
    }


    public function testIsALoggerProxy(): void
    {
        $command = self::loggingCommand();

        self::assertInstanceOf(LoggerProxyInterface::class, $command);
        self::assertInstanceOf(LoggerInterface::class, $command);
        self::assertInstanceOf(LoggerAwareInterface::class, $command);
    }


    public function testRunsWithoutALoggerAndLogsNothing(): void
    {
        $tester = new CommandTester(self::loggingCommand());

        $status = $tester->execute([]);

        self::assertSame(BaseCommand::SUCCESS, $status);
        self::assertSame('', $tester->getDisplay());
    }


    public function testLogsThroughTheInjectedLogger(): void
    {
        $handler = new TestHandler();
        $command = self::loggingCommand();
        $command->setLogger(new Logger('test', [$handler]));

        (new CommandTester($command))->execute([]);

        self::assertTrue($handler->hasInfo('executed'));
        self::assertCount(1, $handler->getRecords());
    }
}
