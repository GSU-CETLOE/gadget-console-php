<?php

declare(strict_types=1);

namespace Gadget\Console\Tests\Command;

use Gadget\Console\Command\Command;
use Gadget\Console\Command\ShellCommand;
use Gadget\Log\LoggerProxyInterface;
use Gadget\Process\ProcessException;
use Gadget\Process\ProcessShellArgs;
use Gadget\Process\ProcessShellEnv;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs real child processes through the local PHP interpreter.
 */
final class ShellCommandTest extends TestCase
{
    /**
     * A shell command that runs each "snippet" argument as a line of PHP in a
     * child process. Tests that need full control over the shell arguments can
     * pass their own resolver instead.
     *
     * @param ProcessShellEnv|null $env
     * @param bool $throwOnError
     * @param (\Closure(InputInterface): (ProcessShellArgs|string[])[])|null $shellArgs
     * @return ShellCommand
     */
    private static function snippetCommand(
        ProcessShellEnv|null $env = null,
        bool $throwOnError = true,
        \Closure|null $shellArgs = null
    ): ShellCommand {
        $shellArgs ??= fn(InputInterface $input): array => array_map(
            fn(string $snippet): array => self::phpArgs($snippet),
            self::snippets($input)
        );

        return new class ($env ?? new ProcessShellEnv(__DIR__), $throwOnError, $shellArgs) extends ShellCommand {
            /**
             * @param ProcessShellEnv $env
             * @param bool $throwOnError
             * @param \Closure(InputInterface): (ProcessShellArgs|string[])[] $shellArgs
             */
            public function __construct(
                ProcessShellEnv $env,
                bool $throwOnError,
                private \Closure $shellArgs
            ) {
                parent::__construct($env, $throwOnError, 'app:shell');
            }


            protected function configure(): void
            {
                $this->addArgument('snippet', InputArgument::IS_ARRAY);
            }


            /** @inheritdoc */
            protected function getShellArgs(InputInterface $input): array
            {
                return ($this->shellArgs)($input);
            }
        };
    }


    /**
     * The "snippet" arguments given to the command, as strings.
     *
     * @param InputInterface $input
     * @return string[]
     */
    private static function snippets(InputInterface $input): array
    {
        $snippets = $input->getArgument('snippet');
        self::assertIsArray($snippets);

        return array_map(
            static function (mixed $snippet): string {
                self::assertIsString($snippet);
                return $snippet;
            },
            $snippets
        );
    }


    /**
     * Arguments that run a snippet of PHP code in a child process.
     *
     * @param string $code
     * @return string[]
     */
    private static function phpArgs(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }


    public function testIsAGadgetCommand(): void
    {
        $command = self::snippetCommand();

        self::assertInstanceOf(Command::class, $command);
        self::assertInstanceOf(LoggerProxyInterface::class, $command);
    }


    public function testRunsEachShellCommandInOrderAndWritesItsOutputToTheConsole(): void
    {
        $tester = new CommandTester(self::snippetCommand());

        $status = $tester->execute(['snippet' => ['echo "one";', 'echo "two";']]);

        self::assertSame(BaseCommand::SUCCESS, $status);
        self::assertSame("one\ntwo\n", $tester->getDisplay());
    }


    public function testAcceptsPreparedShellArgsAlongsidePlainArgumentLists(): void
    {
        $command = self::snippetCommand(shellArgs: fn(InputInterface $input): array => [
            new ProcessShellArgs(self::phpArgs('echo "prepared";')),
            self::phpArgs('echo "plain";'),
        ]);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame("prepared\nplain\n", $tester->getDisplay());
    }


    public function testWritesStandardErrorToTheConsoleAsWell(): void
    {
        $tester = new CommandTester(self::snippetCommand());

        $tester->execute(['snippet' => ['fwrite(STDERR, "oops");']]);

        self::assertSame("oops\n", $tester->getDisplay());
    }


    public function testLogsStandardOutputAtInfoAndStandardErrorAtError(): void
    {
        $handler = new TestHandler();
        $command = self::snippetCommand();
        $command->setLogger(new Logger('test', [$handler]));

        (new CommandTester($command))->execute(['snippet' => ['echo "out";', 'fwrite(STDERR, "err");']]);

        self::assertTrue($handler->hasInfo('out'));
        self::assertTrue($handler->hasError('err'));
        self::assertCount(2, $handler->getRecords());
    }


    public function testRunsInTheConfiguredWorkingDirectoryAndEnvironment(): void
    {
        $env = new ProcessShellEnv(__DIR__, ['GADGET_TEST' => 'console']);
        $tester = new CommandTester(self::snippetCommand($env));

        $tester->execute(['snippet' => ['echo getcwd(), "|", getenv("GADGET_TEST");']]);

        self::assertSame(__DIR__ . "|console\n", $tester->getDisplay());
    }


    public function testThrowsOnANonZeroExitCodeByDefault(): void
    {
        $tester = new CommandTester(self::snippetCommand());

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('Invalid exit code: 3');

        $tester->execute(['snippet' => ['exit(3);']]);
    }


    public function testStopsAtTheFirstFailureWhenThrowing(): void
    {
        $tester = new CommandTester(self::snippetCommand());

        try {
            $tester->execute(['snippet' => ['echo "first";', 'exit(2);', 'echo "never";']]);
            self::fail('Expected a ProcessException');
        } catch (ProcessException) {
            self::assertSame("first\n", $tester->getDisplay());
        }
    }


    public function testKeepsGoingAndReportsSuccessWhenNotThrowingOnError(): void
    {
        $tester = new CommandTester(self::snippetCommand(throwOnError: false));

        $status = $tester->execute(['snippet' => ['exit(2);', 'echo "after";']]);

        self::assertSame(BaseCommand::SUCCESS, $status);
        self::assertSame("after\n", $tester->getDisplay());
    }
}
