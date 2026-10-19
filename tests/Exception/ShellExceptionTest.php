<?php

declare(strict_types=1);

namespace Gadget\Console\Tests\Exception;

use Gadget\Console\Exception\ShellException;
use Gadget\Exception\Exception;
use PHPUnit\Framework\TestCase;

final class ShellExceptionTest extends TestCase
{
    public function testIsAGadgetException(): void
    {
        self::assertInstanceOf(Exception::class, new ShellException('failed'));
    }


    public function testFormatsMessageFromFormatAndArguments(): void
    {
        $exception = new ShellException(['Shell %s exited with %d', ['ls', 2]]);

        self::assertSame('Shell ls exited with 2', $exception->getMessage());
    }


    public function testKeepsThePreviousException(): void
    {
        $previous = new \RuntimeException('root cause');

        $exception = new ShellException('failed', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
