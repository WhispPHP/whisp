<?php

declare(strict_types=1);

namespace Whisp\Concerns;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

trait WritesLogs
{
    use LoggerTrait;

    private LoggerInterface $logger;

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (! isset($this->logger)) {
            return;
        }

        $classPrepend = strtoupper(sprintf('[%s]', str_replace('Whisp\\', '', $this::class)));
        $this->logger->log($level, $classPrepend.' '.$message, $context);
    }
}
