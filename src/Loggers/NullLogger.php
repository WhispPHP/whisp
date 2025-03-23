<?php

declare(strict_types=1);

namespace Whisp\Loggers;

use Psr\Log\AbstractLogger;

class NullLogger extends AbstractLogger
{
    public function __construct() {}

    public function log($level, $message, array $context = []): void {}
}
