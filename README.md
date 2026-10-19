# gadget-console-php

Small additions to [Symfony Console](https://symfony.com/doc/6.4/components/console.html) for command-line apps that log. It gives you:

- A command base class that is also a [PSR-3](https://www.php-fig.org/psr/psr-3/) logger proxy, so `$this->info(...)` works inside `execute()` and goes wherever your app's logger goes.
- A shell command base class. You hand back a list of commands to run; it runs them one after another in a child process, echoes their output to the console, and logs it.
- An event subscriber that logs when each command starts, how long it ran, what exit code it returned, and the full stack trace when it throws. It also tells the `gadget-log-php` command processor which command is running, so every log line carries the command name.

A Symfony bundle is included so all of it autowires.

CETLOE at Georgia State University maintains this package. It started as `mjfk23/gadget-console`, written by Melody Kimball.

## Requirements

- PHP 8.1
- Symfony Console and EventDispatcher 6.4

The package pulls in `gsu-cetloe/gadget-core-php`, `gsu-cetloe/gadget-log-php`, `gsu-cetloe/gadget-process-php`, and `gsu-cetloe/symfony-bundle`.

## Setup

Install [Composer](https://getcomposer.org/) if you don't have it, then add the package to your project:

```sh
composer require gsu-cetloe/gadget-console-php
```

To work on the library itself, clone the repository and install the development dependencies:

```sh
git clone https://github.com/GSU-CETLOE/gadget-console-php.git
cd gadget-console-php
composer install
```

Your machine does not have to run PHP 8.1. Composer is told to resolve dependencies as if it were PHP 8.1.0 no matter what version runs it, so the lock file always reflects what the library targets. Before a release, run the suite on an actual 8.1 build.

## Development commands

| Command | What it does |
| --- | --- |
| `composer test` | Runs the PHPUnit suite in `tests/`. |
| `composer phpstan` | Runs PHPStan at level 10 over `src/`, `bundle/`, and `tests/`. |
| `composer phpcs` | Checks `src/`, `bundle/`, and `tests/` against PSR-12. |
| `composer check` | Runs all three in order. Run this before you commit. |

Anything generated, such as the PHPStan and PHPUnit caches, lands in `working/`. Git ignores that directory.

## What's in the box

Everything lives under the `Gadget\Console` namespace.

| Class | Purpose |
| --- | --- |
| `Command\Command` | Abstract. Symfony's `Command` plus `Gadget\Log\LoggerProxyInterface`. Extend this instead of Symfony's. |
| `Command\ShellCommand` | Abstract. A `Command` that runs a list of shell commands through `Gadget\Process\ProcessShell`. |
| `Event\ConsoleEventHandler` | Event subscriber that logs command start, finish, and errors, and keeps `ConsoleCommandProcessor` up to date. |
| `Exception\ShellException` | A `Gadget\Exception\Exception` for your own shell commands to throw. Nothing in this package throws it. |
| `GadgetConsoleBundle` | Registers the above with a Symfony container. |

### Commands that log

Extend `Command` and log through `$this`. With no logger set, the calls do nothing, so a command works the same in a test as it does in the app.

```php
use Gadget\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('app:import')->addArgument('source');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Importing from {source}', ['source' => $input->getArgument('source')]);
        // ...
        return self::SUCCESS;
    }
}
```

Call `setLogger()` on the command, or let Symfony do it: the class implements `LoggerAwareInterface`, so the framework's autoconfiguration injects the app logger.

### Shell commands

Extend `ShellCommand`, give it a `ProcessShellEnv` (working directory and environment), and return the commands to run from `getShellArgs()`. Each entry is either a plain `string[]` or a prepared `ProcessShellArgs`.

```php
use Gadget\Console\Command\ShellCommand;
use Gadget\Process\ProcessShellEnv;
use Symfony\Component\Console\Input\InputInterface;

final class BuildAssetsCommand extends ShellCommand
{
    protected function configure(): void
    {
        $this->setName('app:build-assets');
    }

    protected function getShellArgs(InputInterface $input): array
    {
        return [
            ['npm', 'ci'],
            ['npm', 'run', 'build'],
        ];
    }
}

$command = new BuildAssetsCommand(new ProcessShellEnv('/srv/app', ['NODE_ENV' => 'production']));
```

The commands run in order. Every line a child process writes is written to the console output as it arrives. Standard output is also logged at `info`, standard error at `error`.

The second constructor argument, `throwOnError`, defaults to `true`: the first non-zero exit code throws `Gadget\Process\ProcessException` with the message `Invalid exit code: N` and nothing after it runs. Pass `false` and the remaining commands still run, but the exit codes are discarded and the command returns `SUCCESS` even if something failed. If you need to know, log it from your output or override `execute()`.

Everything about the shell is behind a protected method (`getShell()`, `getShellInput()`, `getShellOutput()`, `getShellCommands()`), so a subclass can feed stdin or change how output is handled without rewriting `execute()`.

### Logging the life of a command

`ConsoleEventHandler` subscribes to `ConsoleEvents::COMMAND`, `ERROR`, and `TERMINATE`. Wire it into a plain console application like this:

```php
use Gadget\Console\Event\ConsoleEventHandler;
use Gadget\Log\Monolog\Processor\ConsoleCommandProcessor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;

$processor = new ConsoleCommandProcessor();
$logger = (new Logger('app', [new StreamHandler('php://stderr')]))->pushProcessor($processor);

$handler = new ConsoleEventHandler($processor);
$handler->setLogger($logger);

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber($handler);

$application = new Application();
$application->setDispatcher($dispatcher);
$application->add($command);
$application->run();
```

Running `app:import data.csv --dry-run` then logs two lines around the command's own output:

```
Started: {"source":"data.csv","dry-run":true}
Terminated: Code=>0, Elapsed=>00:00:00:01.284
```

The arguments are JSON. Symfony's own options (`help`, `verbose`, `ansi`, `no-interaction`, and so on) and any argument left unset are dropped, so the line shows what the caller actually passed. Elapsed time is `DD:HH:MM:SS.mmm`.

While the command runs, `$processor->commandName` is set to its name, so with `gadget-log-php`'s `ConsoleLogFormatter` the command bracket on every log line is filled in. When it finishes the name is cleared.

If the command throws, each line of the stack trace is logged at `error` before the terminate line, including any chained causes:

```
RuntimeException: boom
 at App.Command.ImportCommand.execute(ImportCommand.php:31)
 at Symfony.Component.Console.Command.Command.run(Command.php:326)
 ...
Terminated: Code=>1, Elapsed=>00:00:00:00.012
```

Commands that run other commands through `$this->getApplication()->doRun(...)` get a slightly different pair of lines, so the log makes the nesting visible:

```
Started: []
Command started: Name=>app:inner, Args=>[]
Command terminated: Name=>app:inner, Code=>0, Elapsed=>00:00:00:00.340
Terminated: Code=>0, Elapsed=>00:00:00:00.355
```

Those two inner lines are stamped with the outer command's name. Lines the inner command logs itself carry its own name, and the outer name is put back when it returns.

## Symfony bundle

Add the bundle to `config/bundles.php`, together with the log bundle that provides `ConsoleCommandProcessor`:

```php
return [
    // ...
    Gadget\Log\GadgetLogBundle::class => ['all' => true],
    Gadget\Console\GadgetConsoleBundle::class => ['all' => true],
];
```

The bundle registers `ConsoleEventHandler` as an autowired, autoconfigured service. FrameworkBundle picks it up as an event subscriber, and because it implements `LoggerAwareInterface` it gets the app logger. The two abstract command classes are not registered; Symfony skips abstract classes when scanning a directory. Your own subclasses register themselves the usual way.

A `ShellCommand` subclass needs a `ProcessShellEnv`. Enable `Gadget\Process\GadgetProcessBundle` and one is autowired with the current directory and the process environment, or define your own service with the working directory you want.

## Testing

`composer test` runs the suite. The shell command tests run real child processes through the local PHP interpreter, the event handler tests drive a real `Application` with an `EventDispatcher` and check which command each log record is stamped with, and the bundle tests compile a real `ContainerBuilder`. [TESTPLAN.md](TESTPLAN.md) has the manual walkthrough.

## License

MIT. See [LICENSE](LICENSE).
