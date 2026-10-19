# Test Plan

Walk through this list before tagging a release or merging a change that touches `composer.json`, the analyzers, or the test suite. Run every command from the repository root.

## Setup

- [ ] `composer install` finishes without errors and without asking to change the lock file.
- [ ] `composer validate` reports the manifest is valid.
- [ ] `working/` exists after the first run and is not shown in `git status`.

## Automated checks

- [ ] `composer phpcs` reports no errors and no warnings for `src/`, `bundle/`, and `tests/`.
- [ ] `composer phpstan` reports `No errors` at level 10 for `src/`, `bundle/`, and `tests/`.
- [ ] `composer test` passes with no warnings, deprecations, or notices in the output.
- [ ] `composer check` runs all three in order and exits 0.

## Functional coverage

Confirm each area has passing tests under `tests/` that cover the listed behaviors.

- [ ] `Command`: is a Symfony `Command`; implements `LoggerProxyInterface`, `LoggerInterface`, and `LoggerAwareInterface`; runs with no logger and logs nothing; logs through an injected logger.
- [ ] `ShellCommand`: is a `Command` and a logger proxy; runs each shell command in order and writes stdout and stderr to the console; accepts `ProcessShellArgs` and plain `string[]` entries together; logs stdout at `info` and stderr at `error`; runs in the configured working directory with the configured environment; a non-zero exit throws `ProcessException` with `Invalid exit code: N` and stops the remaining commands; with `throwOnError` false the remaining commands run and the command returns `SUCCESS`.
- [ ] `ConsoleEventHandler`: is a logger proxy; subscribes to `COMMAND`, `ERROR`, and `TERMINATE` at priority 1; sets `ConsoleCommandProcessor::$commandName` when a command starts; logs `Started: {json}` with arguments and options; drops unset arguments and Symfony's framework options from the JSON; logs nothing and clears the processor when the event has no command; logs `Terminated: Code=>N, Elapsed=>DD:HH:MM:SS.mmm` and clears the processor; terminating without a start logs nothing; logs every line of `StackTraceIterator` at `error`, including chained causes.
- [ ] `ConsoleEventHandler` through a real `Application` with an `EventDispatcher`: the start and terminate records of a top-level command are stamped with its name and the processor is cleared afterwards; a nested command run through `Application::doRun()` logs `Command started` and `Command terminated` lines stamped with the outer command's name, its own log lines carry its own name, and the outer name is restored; a throwing command logs the stack trace, then `Terminated: Code=>1`, and the application exits 1.
- [ ] `ShellException`: extends `Gadget\Exception\Exception`, formats `[format, args]` messages, and keeps the previous exception.
- [ ] `GadgetConsoleBundle`: extends `Bundle` and exposes `GadgetConsoleExtension` under the alias `gadget_console`.
- [ ] `GadgetConsoleExtension`: registers `ConsoleEventHandler` as an autowired, autoconfigured service; does not register the abstract `Command` and `ShellCommand`; a compiled container with a `ConsoleCommandProcessor` service builds the handler.

## Consuming the package

- [ ] In a scratch project, `composer require gsu-cetloe/gadget-console-php` installs `gsu-cetloe/gadget-core-php`, `gsu-cetloe/gadget-log-php`, `gsu-cetloe/gadget-process-php`, `gsu-cetloe/symfony-bundle`, and `symfony/console` and `symfony/event-dispatcher` 6.4.
- [ ] In a scratch Symfony app with `GadgetLogBundle` and `GadgetConsoleBundle` enabled, running any command logs a `Started:` and a `Terminated:` line, and log lines written during the command carry its name in the command bracket of `ConsoleLogFormatter`.
- [ ] In that same app, a command extending `Gadget\Console\Command\Command` receives the app logger without any configuration.
- [ ] With `GadgetProcessBundle` also enabled, a `ShellCommand` subclass autowires its `ProcessShellEnv`.

## Known gaps

- The suite checks that `ConsoleEventHandler` is autoconfigured, but does not boot FrameworkBundle to prove it is tagged as an event subscriber or that the app logger is injected. The manual steps above cover that.
- `ShellException` is defined but nothing in this package throws it. The tests cover the class itself only.
- Tests run on whatever PHP is installed locally. Composer resolves dependencies as if PHP were 8.1.0, but the behavior checked here is that of the local interpreter.
