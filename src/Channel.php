<?php

declare(strict_types=1);

namespace Whisp;

use Whisp\Command\CommandRunner;
use Whisp\Command\PtyCommandRunner;
use Whisp\Concerns\WritesLogs;
use Whisp\Loggers\NullLogger;
use Whisp\Values\TerminalInfo;

class Channel
{
    use WritesLogs;

    private ?TerminalInfo $terminalInfo = null;

    private ?Pty $pty = null;

    private ?Connection $connection = null;

    private bool $inputClosed = false;

    private bool $outputClosed = false;

    private ?int $childPid = null;

    private array $pendingEnv = [];

    private ?CommandRunner $commandRunner = null;

    public function __construct(
        public readonly int $recipientChannel, // Their channel ID
        public readonly int $senderChannel, // Our channel ID
        public readonly int $windowSize,
        public readonly int $maxPacketSize,
        public readonly string $channelType // "session", "x11", etc.
    ) {
        $this->setLogger(new NullLogger);
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
            $this->pty
                ->setLogger($this->logger)
                ->open();

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
     * Read data from the command and forward it to the SSH client
     * This should be called regularly by the connection's main loop
     *
     * @return int The number of bytes written to the client
     */
    public function forwardFromCommand(): int
    {
        // Read and forward immediately
        $chunk = $this->commandRunner->read(8192);
        if ($chunk === '') {
            return 0;
        }

        return $this->connection->writeChannelData($this, $chunk);
    }

    /**
     * Start a command - either with a PTY or without based on whether there's a Pty.
     */
    public function startCommand(string $command): int|bool
    {
        $this->commandRunner = ($this->pty) ? new PtyCommandRunner($this->pty) : new CommandRunner;
        $this->commandRunner->setLogger($this->logger);

        $this->logger->debug(sprintf('Starting command: %s, PTY: %s', $command, $this->pty ? 'yes' : 'no'));

        // Set environment variables
        $this->commandRunner->env('PATH', getenv('PATH'));
        if ($this->terminalInfo) {
            $this->commandRunner->env('TERM', $this->terminalInfo->term);
            $this->commandRunner->env('WHISP_TERM', $this->terminalInfo->term);
            $this->commandRunner->env('WHISP_COLS', (string) $this->terminalInfo->widthChars);
            $this->commandRunner->env('WHISP_ROWS', (string) $this->terminalInfo->heightRows);
            $this->commandRunner->env('WHISP_WIDTH_PX', (string) $this->terminalInfo->widthPixels);
            $this->commandRunner->env('WHISP_HEIGHT_PX', (string) $this->terminalInfo->heightPixels);
        }

        // Env added while we didn't have a PTY, but now we do, so let's ensure we set it
        foreach ($this->pendingEnv as $name => $value) {
            $this->commandRunner->env($name, $value);
        }

        // Log environment variables for debugging
        $this->debug('Command environment variables: '.json_encode($this->commandRunner->getEnvironment()));

        // Start the command and store the child PID first
        $this->childPid = $this->commandRunner->start($command);
        if ($this->childPid === false) {
            $this->error('Failed to start command');

            return false;
        }

        // Now that we have the PID, set up signal handling
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, function ($signo) {
            $this->debug("SIGCHLD received for PID {$this->childPid}");

            if (is_null($this->childPid)) {
                return;
            }

            $status = 0;
            $pid = pcntl_waitpid($this->childPid, $status, WNOHANG);
            if ($pid > 0) {
                // Extract the actual exit code from the status
                $exitCode = pcntl_wexitstatus($status);
                $this->info("Child process {$pid} exited with exit code {$exitCode}");

                // Send the exit status to the client
                if ($this->connection) {
                    $this->connection->sendExitStatus($this, $exitCode); // TODO: This should be in Channel, not Connection. Weird back and forth of responsibilities in Connection and Channel!
                    $this->markOutputClosed();
                }

                $this->childPid = null;
            }
        });

        return $this->childPid;
    }

    /**
     * Write data from SSH client to the running command via PTY
     */
    public function writeToCommand(string $data): int
    {
        return $this->commandRunner->write($data);
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
        if (! is_null($this->commandRunner)) {
            $this->commandRunner->stop();
        }

        if ($this->childPid) {
            $this->debug('Stopping command with PID: '.$this->childPid);
            posix_kill($this->childPid, SIGTERM);
            $this->childPid = null;
        }
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

        $this->markOutputClosed();
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
        $this->pendingEnv[$name] = $value;
        $this->debug("Set environment variable: {$name}={$value}");
    }

    public function commandIsRunning(): bool
    {
        if (! $this->commandRunner) {
            return false;
        }

        return $this->commandRunner->isRunning();
    }

    public function getCommandStdout()
    {
        if (! $this->commandRunner) {
            return null;
        }

        return $this->commandRunner->getStdout();
    }
}
