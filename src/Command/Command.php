<?php

declare(strict_types=1);

namespace Gadget\Console\Command;

use Gadget\Log\LoggerProxyInterface;
use Gadget\Log\LoggerProxyTrait;
use Symfony\Component\Console\Command\Command as BaseCommand;

abstract class Command extends BaseCommand implements LoggerProxyInterface
{
    use LoggerProxyTrait;
}
