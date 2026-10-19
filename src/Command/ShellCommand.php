<?php

declare(strict_types=1);

namespace Gadget\Console\Command;

use Gadget\Process\ProcessShell;
use Gadget\Process\ProcessShellArgs;
use Gadget\Process\ProcessShellEnv;
use Gadget\Process\ProcessShellInput;
use Gadget\Process\ProcessShellOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ShellCommand extends Command
{
    /**
     * @param ProcessShellEnv $shellEnv
     * @param bool $throwOnError
     * @param string|null $name
     */
    public function __construct(
        protected ProcessShellEnv $shellEnv,
        protected bool $throwOnError = true,
        string|null $name = null
    ) {
        parent::__construct($name);
    }


    /** @inheritdoc */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->getShell()->executeAll(
            $this->getShellCommands(
                $this->getShellInput(),
                $this->getShellOutput($output),
                $this->getShellArgs($input)
            ),
            $this->throwOnError
        );

        return self::SUCCESS;
    }


    /**
     * @return ProcessShell
     */
    protected function getShell(): ProcessShell
    {
        return new ProcessShell($this->getShellEnv());
    }


    /**
     * @return ProcessShellEnv
     */
    protected function getShellEnv(): ProcessShellEnv
    {
        return $this->shellEnv;
    }


    /**
     * @return ProcessShellInput
     */
    protected function getShellInput(): ProcessShellInput
    {
        return new ProcessShellInput();
    }


    /**
     * @param OutputInterface $output
     * @return ProcessShellOutput
     */
    protected function getShellOutput(OutputInterface $output): ProcessShellOutput
    {
        return new ProcessShellOutput(function (string $type, string $message) use ($output): void {
            $output->writeln($message);

            if ($type === ProcessShell::ERR) {
                $this->error($message);
            } else {
                $this->info($message);
            }
        });
    }


    /**
     * @param ProcessShellInput $shellInput
     * @param ProcessShellOutput $shellOutput
     * @param (ProcessShellArgs|string[])[] $shellArgs
     * @return array{ProcessShellArgs,ProcessShellInput,ProcessShellOutput}[]
     */
    protected function getShellCommands(
        ProcessShellInput $shellInput,
        ProcessShellOutput $shellOutput,
        array $shellArgs
    ): array {
        return array_map(
            fn(ProcessShellArgs|array $args): array => [
                is_array($args) ? new ProcessShellArgs($args) : $args,
                $shellInput,
                $shellOutput
            ],
            $shellArgs
        );
    }


    /**
     * @param InputInterface $input
     * @return (ProcessShellArgs|string[])[]
     */
    abstract protected function getShellArgs(InputInterface $input): array;
}
