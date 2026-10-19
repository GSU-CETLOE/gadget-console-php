<?php

declare(strict_types=1);

namespace Gadget\Console\Tests\Event;

use Gadget\Console\Command\Command;
use Gadget\Console\Event\ConsoleEventHandler;
use Gadget\Exception\StackTraceIterator;
use Gadget\Log\LoggerProxyInterface;
use Gadget\Log\Monolog\Processor\ConsoleCommandProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ConsoleEventHandlerTest extends TestCase
{
    private const ELAPSED = '\d{2}:\d{2}:\d{2}:\d{2}\.\d{3}';

    private TestHandler $records;
    private Logger $logger;
    private ConsoleCommandProcessor $processor;
    private ConsoleEventHandler $handler;


    protected function setUp(): void
    {
        $this->records = new TestHandler();
        $this->processor = new ConsoleCommandProcessor();
        $this->logger = (new Logger('test', [$this->records]))->pushProcessor($this->processor);
        $this->handler = new ConsoleEventHandler($this->processor);
        $this->handler->setLogger($this->logger);
    }


    /**
     * A console command that does nothing but succeed, with one "source"
     * argument so the logged arguments have something to show.
     *
     * @param string $name
     * @return BaseCommand
     */
    private static function plainCommand(string $name): BaseCommand
    {
        $command = new BaseCommand($name);
        $command->addArgument('source');
        $command->setCode(static fn(): int => BaseCommand::SUCCESS);
        return $command;
    }


    /**
     * A plain command with two options added, so option logging is covered too.
     *
     * @param string $name
     * @return BaseCommand
     */
    private static function importCommand(string $name = 'app:import'): BaseCommand
    {
        $command = self::plainCommand($name);
        $command->addOption('dry-run', null, InputOption::VALUE_NONE);
        $command->addOption('limit', null, InputOption::VALUE_REQUIRED, '', 10);
        return $command;
    }


    /**
     * Input bound to the command's own definition, as it would be after
     * Symfony has parsed the command line.
     *
     * @param BaseCommand $command
     * @param array<string, mixed> $parameters
     * @return InputInterface
     */
    private static function inputFor(BaseCommand $command, array $parameters = []): InputInterface
    {
        return new ArrayInput($parameters, $command->getDefinition());
    }


    private function commandEvent(BaseCommand|null $command, InputInterface $input): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent($command, $input, new BufferedOutput());
    }


    private function terminateEvent(BaseCommand $command, InputInterface $input, int $exitCode): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent($command, $input, new BufferedOutput(), $exitCode);
    }


    /**
     * Every logged message, optionally only those at one level.
     *
     * @param Level|null $level
     * @return list<string>
     */
    private function messages(Level|null $level = null): array
    {
        return array_values(array_map(
            fn(LogRecord $record): string => $record->message,
            array_filter(
                $this->records->getRecords(),
                fn(LogRecord $record): bool => $level === null || $record->level === $level
            )
        ));
    }


    /**
     * Checks each logged record against an expected message pattern and the
     * command name the processor stamped on it.
     *
     * @param list<array{string, string}> $expected [message regex, command name] per record
     * @return void
     */
    private function assertRecords(array $expected): void
    {
        $records = $this->records->getRecords();
        self::assertCount(count($expected), $records, implode("\n", $this->messages()));

        foreach ($records as $index => $record) {
            [$pattern, $command] = $expected[$index];
            self::assertMatchesRegularExpression($pattern, $record->message);
            self::assertSame($command, $record->extra['command'], "record $index: $record->message");
        }
    }


    public function testIsALoggerProxy(): void
    {
        self::assertInstanceOf(LoggerProxyInterface::class, $this->handler);
    }


    public function testSubscribesToCommandErrorAndTerminateEvents(): void
    {
        self::assertSame(
            [
                ConsoleEvents::COMMAND => ['onConsoleCommand', 1],
                ConsoleEvents::ERROR => ['onConsoleError', 1],
                ConsoleEvents::TERMINATE => ['onConsoleTerminate', 1],
            ],
            ConsoleEventHandler::getSubscribedEvents()
        );
    }


    public function testRecordsTheRunningCommandNameOnTheProcessor(): void
    {
        $command = self::importCommand();

        $this->handler->onConsoleCommand($this->commandEvent($command, self::inputFor($command)));

        self::assertSame('app:import', $this->processor->commandName);
    }


    public function testLogsTheArgumentsAndOptionsOfATopLevelCommandAsJson(): void
    {
        $command = self::importCommand();
        $input = self::inputFor($command, ['source' => 'data.csv', '--dry-run' => true]);

        $this->handler->onConsoleCommand($this->commandEvent($command, $input));

        self::assertSame(['Started: {"source":"data.csv","dry-run":true,"limit":10}'], $this->messages());
    }


    public function testLeavesUnsetArgumentsOutOfTheLog(): void
    {
        $command = self::plainCommand('app:plain');

        $this->handler->onConsoleCommand($this->commandEvent($command, self::inputFor($command)));

        self::assertSame(['Started: []'], $this->messages());
    }


    public function testLogsNothingAndClearsTheProcessorWhenTheEventHasNoCommand(): void
    {
        $this->processor->commandName = 'stale';

        $this->handler->onConsoleCommand($this->commandEvent(null, new ArrayInput([])));

        self::assertSame([], $this->messages());
        self::assertNull($this->processor->commandName);
    }


    public function testLogsTheExitCodeAndElapsedTimeWhenTheCommandTerminates(): void
    {
        $command = self::importCommand();
        $input = self::inputFor($command);
        $this->handler->onConsoleCommand($this->commandEvent($command, $input));

        $this->handler->onConsoleTerminate($this->terminateEvent($command, $input, 7));

        self::assertCount(2, $this->messages());
        self::assertMatchesRegularExpression(
            '/^Terminated: Code=>7, Elapsed=>' . self::ELAPSED . '$/',
            $this->messages()[1]
        );
        self::assertNull($this->processor->commandName);
    }


    public function testTerminatingWithoutAMatchingStartLogsNothing(): void
    {
        $command = self::importCommand();
        $this->processor->commandName = 'stale';

        $this->handler->onConsoleTerminate($this->terminateEvent($command, self::inputFor($command), 0));

        self::assertSame([], $this->messages());
        self::assertNull($this->processor->commandName);
    }


    public function testLogsEveryLineOfTheStackTraceAsAnError(): void
    {
        $command = self::importCommand();
        $error = new \RuntimeException('boom', 0, new \LogicException('root cause'));
        $event = new ConsoleErrorEvent(self::inputFor($command), new BufferedOutput(), $error, $command);

        $this->handler->onConsoleError($event);

        $expected = iterator_to_array(new StackTraceIterator($error), false);
        self::assertSame($expected, $this->messages(Level::Error));
        self::assertSame($expected, $this->messages());
        self::assertSame('RuntimeException: boom', $expected[0]);
        self::assertContains('Caused by: LogicException: root cause', $expected);
    }


    /**
     * A console application with the handler subscribed, so events fire the
     * way they do in production.
     *
     * @param BaseCommand ...$commands
     * @return ApplicationTester
     */
    private function application(BaseCommand ...$commands): ApplicationTester
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->handler);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setDispatcher($dispatcher);
        foreach ($commands as $command) {
            if ($command instanceof Command) {
                $command->setLogger($this->logger);
            }
            $application->add($command);
        }

        return new ApplicationTester($application);
    }


    /**
     * A Gadget command that logs one line when it runs.
     *
     * @param string $name
     * @param string $message
     * @return Command
     */
    private static function talkingCommand(string $name, string $message): Command
    {
        return new class ($name, $message) extends Command {
            public function __construct(string $name, private string $message)
            {
                parent::__construct($name);
            }


            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->info($this->message);
                return self::SUCCESS;
            }
        };
    }


    public function testLogsTheLifecycleOfATopLevelCommandUnderItsOwnName(): void
    {
        $tester = $this->application(self::importCommand());

        $status = $tester->run(['command' => 'app:import', 'source' => 'data.csv', '--dry-run' => true]);

        self::assertSame(BaseCommand::SUCCESS, $status);
        $this->assertRecords([
            ['/^Started: {"source":"data.csv","dry-run":true,"limit":10}$/', 'app:import'],
            ['/^Terminated: Code=>0, Elapsed=>' . self::ELAPSED . '$/', 'app:import'],
        ]);
        self::assertNull($this->processor->commandName);
    }


    public function testDropsTheFrameworkOptionsFromTheLoggedArguments(): void
    {
        $tester = $this->application(self::plainCommand('app:plain'));

        $status = $tester->run([
            'command' => 'app:plain',
            'source' => 'data.csv',
            '--verbose' => true,
            '--no-interaction' => true,
            '--ansi' => true,
        ]);

        self::assertSame(BaseCommand::SUCCESS, $status);
        self::assertSame('Started: {"source":"data.csv"}', $this->messages()[0]);
    }


    public function testAttributesANestedCommandToTheCommandThatCalledIt(): void
    {
        $outer = new class ('app:outer') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $application = $this->getApplication() ?? throw new \LogicException('No application');
                $this->info('outer before');
                $application->doRun(new ArrayInput(['command' => 'app:inner']), $output);
                $this->info('outer after');
                return self::SUCCESS;
            }
        };
        $tester = $this->application($outer, self::talkingCommand('app:inner', 'inner running'));

        $status = $tester->run(['command' => 'app:outer']);

        self::assertSame(BaseCommand::SUCCESS, $status);
        $this->assertRecords([
            ['/^Started: \[\]$/', 'app:outer'],
            ['/^outer before$/', 'app:outer'],
            ['/^Command started: Name=>app:inner, Args=>\[\]$/', 'app:outer'],
            ['/^inner running$/', 'app:inner'],
            ['/^Command terminated: Name=>app:inner, Code=>0, Elapsed=>' . self::ELAPSED . '$/', 'app:outer'],
            ['/^outer after$/', 'app:outer'],
            ['/^Terminated: Code=>0, Elapsed=>' . self::ELAPSED . '$/', 'app:outer'],
        ]);
        self::assertNull($this->processor->commandName);
    }


    public function testLogsTheStackTraceAndStillLogsTerminationWhenACommandThrows(): void
    {
        $failing = new class ('app:fail') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('boom');
            }
        };
        $tester = $this->application($failing);

        $status = $tester->run(['command' => 'app:fail']);

        self::assertSame(BaseCommand::FAILURE, $status);
        $errors = $this->messages(Level::Error);
        self::assertSame('RuntimeException: boom', $errors[0]);
        self::assertGreaterThan(1, count($errors));
        self::assertMatchesRegularExpression(
            '/^Terminated: Code=>1, Elapsed=>' . self::ELAPSED . '$/',
            $this->messages()[count($this->messages()) - 1]
        );
        self::assertNull($this->processor->commandName);
    }
}
