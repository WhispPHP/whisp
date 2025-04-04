<?php

declare(strict_types=1);

namespace Whisp\Loggers;

use Psr\Log\LoggerInterface;
use Stringable;

class ConsoleLogger implements LoggerInterface
{
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        echo "[" . date('H:i:s') . "] $level: $message\n";
    }
}
