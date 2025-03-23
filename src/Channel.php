<?php

declare(strict_types=1);

namespace Whisp;

use Psr\Log\LoggerInterface;
use Whisp\Loggers\FileLogger;
use Whisp\Values\TerminalInfo;

class Channel
{
    private ?TerminalInfo $terminalInfo = null;

    private ?Pty $pty = null;

    private ?Connection $connection = null;

    private LoggerInterface $logger;

    private bool $inputClosed = false;

    private bool $outputClosed = false;

    private array $environment = [];

    private $process = null; // Process resource

    private $pipes = []; // Process pipes

    private bool $commandRunning = false;

    private ?int $childPid = null;

    public function __construct(
        public readonly int $recipientChannel, // Their channel ID
        public readonly int $senderChannel, // Our channel ID
        public readonly int $windowSize,
        public readonly int $maxPacketSize,
        public readonly string $channelType // "session", "x11", etc.
    ) {
        $this->logger = new FileLogger(realpath(__DIR__.'/../').'/server.log');
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
            [$master, $slave] = $this->pty->open();
            $slaveName = $this->pty->getSlaveName();

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
     * Start a command connected to the PTY
     */
    public function startCommand(string $command): bool
    {
        if (! $this->pty) {
            $this->logger->debug('No PTY, creating one');
            if (! $this->createPty()) {
                $this->logger->error('Failed to create PTY');

                return false;
            }
        }

        // Get master/slave streams
        $master = $this->pty->getMaster();
        $slave = $this->pty->getSlave();
        $slaveName = $this->pty->getSlaveName();
        if (! is_resource($master) || ! is_resource($slave)) {
            $this->logger->error('Failed to get PTY streams');

            return false;
        }

        // Set environment variables
        $env = $this->environment;
        if ($this->terminalInfo) {
            $env['TERM'] = $this->terminalInfo->term;
        }

        // Log environment variables for debugging
        $this->logger->debug('Command environment variables: '.json_encode($env));

        // Set up descriptor spec
        $descriptorSpec = [
            0 => ['file', $slaveName, 'r'],  // stdin
            1 => ['file', $slaveName, 'w'],  // stdout
            2 => ['file', $slaveName, 'w'],   // stderr
        ];

        $this->logger->debug('Starting command: '.$command);

        // Set up SIGCHLD handler before starting the process
        pcntl_async_signals(true);
        pcntl_signal(SIGCHLD, function ($signo, $siginfo) {
            if (! $siginfo) {
                return;
            }

            $this->logger->debug('Received SIGCHLD for pid: '.$siginfo['pid']);

            // Check if this signal is for our child process
            if ($siginfo['pid'] === $this->childPid) {
                $this->logger->debug('Child process terminated, closing channel');
                $this->closeChannel();
            }
        });

        // Start the process
        $this->process = proc_open($command, $descriptorSpec, $this->pipes, null, $env);

        if (! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);
        $this->childPid = $status['pid'];

        $this->commandRunning = true;

        // Make the master non-blocking for reading
        stream_set_blocking($master, false);

        return true;
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
     * Read data from the PTY and forward it to the SSH client
     * This should be called regularly by the connection's main loop
     */
    public function forwardFromPty(): void
    {
        $master = $this->pty->getMaster();
        if (! is_resource($master)) {
            $this->logger->debug('PTY master is no longer a valid resource - closing channel');
            $this->closeChannel();

            return;
        }

        // Try to read from the PTY master
        $data = @fread($master, 8192);
        if ($data === false) {
            $this->logger->debug('Failed to read from PTY - closing channel');
            $this->closeChannel();

            return;
        }

        // No data to forward from PTY
        if ($data === '') {
            return;
        }

        // Forward the data to the SSH client
        $this->connection->writeChannelData($this, $data);
    }

    /**
     * Helper method to close channel and send necessary messages
     */
    private function closeChannel(): void
    {
        $this->commandRunning = false;

        // Try to read/write any remaining data from the PTY before closing
        if ($this->pty && $this->connection) {
            $master = $this->pty->getMaster();
            if (is_resource($master)) {
                // Give a small window for any final output to arrive
                usleep(50000);

                // Read in a loop until we get no more data
                $attempts = 0;
                while (true && $attempts < 10) {
                    $data = @fread($master, 8192);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $this->connection->writeChannelData($this, $data);
                    $attempts++;
                }
                usleep(100000); // Give a small window for any final output to arrive
            }
        }

        $this->markOutputClosed();

        if ($this->connection) {
            $this->connection->disconnect('App finished');
        }
    }

    /**
     * Mark input as closed (EOF received)
     */
    public function markInputClosed(): void
    {
        $this->inputClosed = true;

        if ($this->process && is_resource($this->process) && $this->pty) {
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
        if ($this->process && is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            proc_close($this->process);
        }

        $this->commandRunning = false;
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
        $this->environment[$name] = $value;
        $this->logger->debug("Set environment variable: {$name}={$value}");
    }
}
