<?php

declare(strict_types=1);

namespace Whisp\Loggers;

use DateTime;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class FileLogger extends AbstractLogger
{
    private $fp;

    public function __construct(private string $logFile)
    {
        $this->fp = fopen($logFile, 'a');
        fwrite($this->fp, str_repeat('-', 80).PHP_EOL);
    }

    public function log($level, $message, array $context = []): void
    {
        // Ignore debug for now
        if ($level === LogLevel::DEBUG) {
            return;
        }

        $logMessage = sprintf('%s [%s] %s %s', (new DateTime)->format('Y-m-d H:i:s'), $level, $message, PHP_EOL);
        fwrite($this->fp, $logMessage);
    }

    public function __destruct()
    {
        fclose($this->fp);
    }
}
