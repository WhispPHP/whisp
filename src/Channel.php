<?php

declare(strict_types=1);

namespace Whisp;

use Psr\Log\LoggerInterface;
use Whisp\Loggers\NullLogger;
use Whisp\Values\TerminalInfo;

class Channel
{
    private ?TerminalInfo $terminalInfo = null;

    private ?Pty $pty = null;

    private ?Connection $connection = null;

    private LoggerInterface $logger;

    private bool $inputClosed = false;

    private bool $outputClosed = false;

    private $process = null; // Process resource

    private ?int $childPid = null;

    public function __construct(
        public readonly int $recipientChannel, // Their channel ID
        public readonly int $senderChannel, // Our channel ID
        public readonly int $windowSize,
        public readonly int $maxPacketSize,
        public readonly string $channelType // "session", "x11", etc.
    ) {
        $this->logger = new NullLogger;
    }

    /**
     * Store terminal information from pty-req
     */
    public function setTerminalInfo(
        string $term,
        int $widthChars,
        int $heightRows,
        int $widthPixels,
        int $heightPixels,
        array $modes
    ): void {
        $this->terminalInfo = new TerminalInfo(
            $term,
            $widthChars,
            $heightRows,
            $widthPixels,
            $heightPixels,
            $modes
        );

        // If we have a PTY, configure it
        if ($this->pty) {
            $this->pty->setupTerminal(
                $term,
                $widthChars,
                $heightRows,
                $widthPixels,
                $heightPixels,
                $modes
            );
        }
    }

    /**
     * Create and attach a PTY to this channel
     *
     * @throws \RuntimeException if PTY creation fails
     */
    public function createPty(): bool
    {
        try {
            $this->pty = new Pty;
            $this->pty->open();

            if ($this->terminalInfo) {
                $this->pty->setupTerminal(
                    $this->terminalInfo->term,
                    $this->terminalInfo->widthChars,
                    $this->terminalInfo->heightRows,
                    $this->terminalInfo->widthPixels,
                    $this->terminalInfo->heightPixels,
                    $this->terminalInfo->modes
                );
            }

            return true;
        } catch (\Exception $e) {
            // Clean up the failed PTY
            if ($this->pty) {
                $this->pty->close();
                $this->pty = null;
            }
            // Re-throw with more context
            throw new \RuntimeException('Failed to create PTY: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Read data from the PTY and forward it to the SSH client
     * This should be called regularly by the connection's main loop
     *
     * @return int The number of bytes written to the client
     */
    public function forwardFromPty(): int
    {
        if (! $this->pty) {
            return 0;
        }

        // Read and forward immediately
        $chunk = $this->pty->read(8192);
        if ($chunk === '') {
            return 0;
        }

        return $this->connection->writeChannelData($this, $chunk);
    }

    /**
     * Start a command connected to the PTY
     */
    public function startCommand(string $command): int|bool
    {
        if (! $this->pty) {
            $this->logger->debug('No PTY, creating one');
            if (! $this->createPty()) {
                $this->logger->error('Failed to create PTY');

                return false;
            }
        }

        // Set environment variables
        if ($this->terminalInfo) {
            $this->pty->setEnvironmentVariable('TERM', $this->terminalInfo->term);
            $this->pty->setEnvironmentVariable('PATH', getenv('PATH'));
        }

        // Log environment variables for debugging
        $this->logger->debug('Command environment variables: '.json_encode($this->pty->getEnvironment()));

        $this->logger->debug('Starting command: '.$command);

        // Start the command and store the child PID first
        $this->childPid = $this->pty->startCommand($command);
        if ($this->childPid === false) {
            $this->logger->error('Failed to start command');

            return false;
        }

        // Now that we have the PID, set up signal handling
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, function ($signo) {
            $this->logger->debug("SIGCHLD received for PID {$this->childPid}");

            if ($signo === SIGCHLD && $this->childPid !== null) {
                $status = 0;
                $pid = pcntl_waitpid($this->childPid, $status, WNOHANG);
                $this->logger->debug("waitpid returned: pid={$pid}, status={$status}");

                if ($pid > 0) {
                    $this->logger->debug("Child process {$pid} exited with status {$status}");
                    $this->process = null;
                    $this->childPid = null;

                    // Close the channel when the command exits
                    $this->closeChannel();
                } else {
                    $this->logger->debug("waitpid returned {$pid} - process not finished yet");
                }
            }
        });

        return $this->childPid;
    }

    /**
     * Write data from SSH client to the running command via PTY
     */
    public function writeToPty(string $data): int
    {
        if (! $this->pty) {
            return 0;
        }

        return $this->pty->write($data);
    }

    /**
     * Helper method to close channel and send necessary messages
     */
    private function closeChannel(): void
    {
        $this->logger->debug("Closing channel for PID {$this->childPid}");

        if ($this->pty) {
            $this->logger->debug('Stopping command in PTY');
            $this->pty->stopCommand();
        }

        $this->markOutputClosed();

        if ($this->connection) {
            $this->logger->debug('Disconnecting connection');
            $this->connection->disconnect('App finished');
        }
    }

    /**
     * Mark input as closed (EOF received)
     */
    public function markInputClosed(): void
    {
        $this->inputClosed = true;

        if ($this->pty) {
            // Send EOF to the process via the PTY
            $this->pty->write("\x04"); // Ctrl+D (EOF)
        }
    }

    /**
     * Mark output as closed
     */
    public function markOutputClosed(): void
    {
        $this->outputClosed = true;
    }

    /**
     * Check if the channel is fully closed
     */
    public function isClosed(): bool
    {
        return $this->inputClosed && $this->outputClosed;
    }

    /**
     * Get terminal information if available
     */
    public function getTerminalInfo(): ?TerminalInfo
    {
        return $this->terminalInfo;
    }

    /**
     * Get the channel's PTY
     */
    public function getPty(): ?Pty
    {
        return $this->pty;
    }

    /**
     * Stop the running command
     */
    public function stopCommand(): void
    {
        if ($this->pty) {
            $this->pty->stopCommand();
        }

        if ($this->childPid) {
            $this->logger->debug('Stopping command with PID: '.$this->childPid);
            posix_kill($this->childPid, SIGTERM);
            $this->childPid = null;
        }

        if ($this->process && is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            proc_close($this->process);
        }

        $this->process = null;
    }

    /**
     * Close the PTY and command
     */
    public function close(): void
    {
        // First stop any running command
        $this->stopCommand();

        // Close the PTY
        if ($this->pty) {
            $this->pty->close();
            $this->pty = null;
        }
    }

    /**
     * Set the connection for this channel
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Set an environment variable for the command
     */
    public function setEnvironmentVariable(string $name, string $value): void
    {
        $this->pty->setEnvironmentVariable($name, $value);
        $this->logger->debug("Set environment variable: {$name}={$value}");
    }
}
