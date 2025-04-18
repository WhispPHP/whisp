<?php

declare(strict_types=1);

namespace Whisp\Command;

use Whisp\Concerns\WritesLogs;

class CommandRunner
{
    use WritesLogs;

    protected array $env = [];
    protected ?int $childPid = null;
    protected bool $running = false;
    protected $process = null;
    protected array $pipes = [];

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function env(string $name, string $value): void
    {
        $this->env[$name] = $value;
    }

    public function getEnvironment(): array
    {
        return $this->env;
    }

    public function read(int $length = 2048): string
    {
        return fread($this->getStdout(), $length);
    }

    public function write(string $data): int
    {
        return fwrite($this->getStdin(), $data);
    }

    public function getStdin()
    {
        return $this->pipes[0];
    }

    public function getStdout()
    {
        return $this->pipes[1];
    }

    public function getStderr()
    {
        return $this->pipes[2];
    }

    /**
     * Start the command and return the child PID
     */
    public function start(string $command): int|false
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open(
            $command,
            $descriptorSpec,
            $this->pipes,
            null,
            $this->env
        );

        if (! is_resource($this->process)) {
            throw new \RuntimeException('Failed to start process: '.error_get_last()['message'] ?? 'unknown error');
        }

        $status = proc_get_status($this->process);
        $this->childPid = $status['pid'];
        $this->running = true;

        $this->info(sprintf('Started command: %s with pid %d', $command, $this->childPid));

        // Make master stream non-blocking for reading from the process
        stream_set_blocking($this->pipes[0], false);

        // Verify process is still running
        $status = proc_get_status($this->process);
        if (! $status['running']) {
            throw new \RuntimeException("Process exited immediately with status {$status['exitcode']}");
        }

        // Set up SIGCHLD handler
        pcntl_signal(SIGCHLD, function ($signo) {
            if ($signo === SIGCHLD) {
                $status = 0;
                $pid = pcntl_wait($status);
                if ($pid > 0) {
                    $this->debug(sprintf('proc_open child process %d exited with status %d', $pid, $status['exitcode']));
                    $this->running = false;
                    $this->process = null;
                    $this->childPid = null;
                }
            }
        });

        return $this->childPid;
    }

    public function stop(): void
    {
        if ($this->process && is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            if (is_resource($this->process)) {
                proc_close($this->process);
            }
        }

        $this->running = false;
        $this->process = null;
        $this->childPid = null;
    }
}
