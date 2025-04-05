<?php

declare(strict_types=1);

declare(ticks=1);

namespace Whisp;

use Socket;
use Whisp\Concerns\WritesLogs;
use Whisp\Loggers\NullLogger;

/**
 * Creates TCP socket, accepts new connections, and spawns them as a child process to Connection.php
 */
class Server
{
    use WritesLogs;

    private array $apps = [];

    private ?Socket $socket = null;

    private bool $isRunning = false;

    private array $childProcesses = [];

    private ServerHostKey $hostKey;

    public function __construct(
        public readonly int $port = 22,
        public readonly string $host = '0.0.0.0',
        bool $autoDiscoverApps = true,
    ) {
        $this->setLogger(new NullLogger);
        $this->hostKey = new ServerHostKey;
        if ($autoDiscoverApps) {
            $this->autoDiscoverApps();
        }
    }

    public function setServerHostKey(ServerHostKey $hostKey): self
    {
        $this->hostKey = $hostKey;

        return $this;
    }

    public function getSocket(): ?Socket
    {
        return $this->socket;
    }

    public function getActiveCount(): int
    {
        return count($this->childProcesses);
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Get the current child processes
     *
     * @return array<int, int> Array of PID => connectionId
     */
    public function getChildProcesses(): array
    {
        return $this->childProcesses;
    }

    /**
     * Auto discover apps in the apps directory, if they start with a lowercase letter
     */
    public function autoDiscoverApps(): self
    {
        // This won't work actually as we're included as a composer package so we'll be in vendor/whispphp/whisp/src/... hmmm..
        // Is there another way to get the base path of where the main server script that uses this class is running?
        $baseDir = dirname(realpath($_SERVER['argv'][0]));
        $autoDiscoverFiles = glob($baseDir.'/apps/[a-z]*.php');

        $apps = [];
        // TODO: How do we set the 'default' app? :thinking: Ask users to just create 'default.php' and require another of their apps?
        foreach ($autoDiscoverFiles as $file) {
            $appName = strtolower(basename($file, '.php'));
            $appName = str_replace(['[', ']'], ['{', '}'], $appName);
            $apps[$appName] = $file;
        }

        return $this->addApps($apps);
    }

    /**
     * Add apps - appends to any existing apps
     *
     * @param  string|array<string, string>  $apps  - e.g. ['default' => 'fullPathToScript.php', 'guestbook' => 'guestbook.php']
     */
    public function addApps(string|array $apps): self
    {
        $apps = is_array($apps) ? $apps : ['default' => $apps];

        // Prepend each 'app' with the PHP binary - we only support PHP scripts for now
        array_walk($apps, function (&$path, $app) {
            $path = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($path));
        });

        // We're adding apps, not replacing them all
        $this->apps = array_merge($this->apps, $apps);

        return $this;
    }

    /**
     * Run the server with the provided supported apps
     *
     * @param  string|array<string, string>  $apps  - e.g. ['default' => 'fullPathToScript.php', 'guestbook' => 'guestbook.php']
     */
    public function run(string|array $apps = []): void
    {
        if (! empty($apps)) {
            $this->addApps($apps);
        }
        $this->start();
    }

    /**
     * Starts the SSH server
     * - sets up signal handlers
     * - creates a TCP socket
     * - enters the main loop
     * - stops the server
     */
    private function start(): void
    {
        $this->setupSignalHandlers(); // For the parent
        $this->createTcpSocket();
        $this->loop();
        $this->stop();
    }

    /**
     * Create and configure the TCP socket for accepting connections
     */
    private function createTcpSocket(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($this->socket);
        $bound = socket_bind($this->socket, $this->host, $this->port);
        if ($bound === false) {
            $this->errorAndExit(sprintf('Failed to bind to %s:%d: %s', $this->host, $this->port, socket_strerror(socket_last_error())));
        }

        $listening = socket_listen($this->socket);
        if ($listening === false) {
            $this->errorAndExit(sprintf('Failed to listen on %s:%d: %s', $this->host, $this->port, socket_strerror(socket_last_error())));
        }

        $this->header(sprintf('🧞 Whisp listening on %s:%d... (PID=%d) 🔮', $this->host, $this->port, getmypid()));
    }

    /**
     * Handle the main accept loop for incoming connections
     */
    private function loop(): void
    {
        $connectionId = 0;
        $this->isRunning = true;

        while ($this->isRunning) {
            $read = [$this->socket];
            $write = $except = [];

            // Use a short timeout to allow signal processing
            if (@socket_select($read, $write, $except, 0, 30000)) {
                $clientSocket = @socket_accept($this->socket);
                if ($clientSocket === false) {
                    continue;
                }
                $connectionId++;

                $this->forkNewConnection($clientSocket, $connectionId);
            }
        }
    }

    private function forkNewConnection(Socket $clientSocket, int $connectionId): void
    {
        socket_getpeername($clientSocket, $address, $port);
        $this->info("Connection #{$connectionId} accepted from {$address}:{$port}");

        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->error("Failed to fork for connection #$connectionId");
            socket_close($clientSocket);

            return;
        }

        if ($pid == 0) {
            $this->debug("Child process {$pid} created for connection #{$connectionId}");

            // Child process - reset signal handlers
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGCHLD, SIG_DFL);

            socket_close($this->socket); // Close parent socket in child

            (new Connection($clientSocket))
                ->logger($this->logger)
                ->apps($this->apps)
                ->connectionId($connectionId)
                ->serverHostKey($this->hostKey)
                ->handle();

            $this->debug("Connection #{$connectionId} handled");
            exit(0); // Exit child process when done
        } else {
            // Parent process
            socket_close($clientSocket); // Close client socket in parent
            $this->childProcesses[$pid] = $connectionId;
        }
    }

    /**
     * Setup signal handlers for the parent process
     */
    private function setupSignalHandlers(): void
    {
        pcntl_async_signals(false);

        pcntl_signal(SIGCHLD, function ($signo) {
            $this->handleChildSignal($signo);
        });

        pcntl_signal(SIGINT, function ($signo) {
            $this->info('Caught SIGINT in parent (PID='.getmypid().'), shutting down...');
            $this->isRunning = false;
        });

        pcntl_signal(SIGTERM, function ($signo) {
            $this->info('Caught SIGTERM in parent (PID='.getmypid().'), shutting down...');
            $this->isRunning = false;
        });
    }

    /**
     * Handle child process signals (SIGCHLD)
     */
    private function handleChildSignal(int $signo): void
    {
        // Reap all finished child processes
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $connectionId = $this->childProcesses[$pid] ?? 'unknown';
            $this->info("Child process {$pid} (connection #{$connectionId}) terminated");
            unset($this->childProcesses[$pid]);
        }
    }

    public function stop(): void
    {
        $this->isRunning = false;
        $this->info('Shutting down...');

        // Close listening socket
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }

        // Terminate all child processes
        $this->info(sprintf('Terminating %d child processes', count($this->childProcesses)));
        foreach ($this->childProcesses as $pid => $connectionId) {
            posix_kill($pid, SIGTERM);
            $this->info("Sent SIGTERM to child process {$pid} (connection #{$connectionId})");
        }

        // Wait for children to terminate (with timeout)
        $timeout = time() + 5;
        while (! empty($this->childProcesses) && time() < $timeout) {
            $this->handleChildSignal(SIGCHLD);
            usleep(50000); // 50ms
        }

        // Force kill any remaining children
        foreach ($this->childProcesses as $pid => $connectionId) {
            posix_kill($pid, SIGKILL);
            $this->warning("Force killed child process {$pid} (connection #{$connectionId})");
        }

        $this->info('Server stopped');
    }

    private function header(string $text): void
    {
        echo "\033[42;30;1m{$text}\033[0m".PHP_EOL;
        $this->logger->info($text);
    }

    private function info(string $text): void
    {
        echo "\033[7m{$text}\033[0m".PHP_EOL;
        $this->logger->info($text);
    }

    private function error(string $text): void
    {
        echo "\033[31;1m{$text}\033[0m".PHP_EOL;
        $this->logger->error($text);
    }

    private function errorAndExit(string $text, int $exitCode = 1): void
    {
        $this->error($text);
        exit($exitCode);
    }
}
