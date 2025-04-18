<?php

declare(strict_types=1);

namespace Whisp\Command;

use Whisp\Pty;

class PtyCommandRunner extends CommandRunner
{
    private Pty $pty;

    public function __construct(Pty $pty)
    {
        $this->pty = $pty;
    }

    public function read(int $length = 2048): string
    {
        return $this->pty->read($length);
    }

    public function write(string $data): int
    {
        return $this->pty->write($data);
    }

    public function getStdout()
    {
        return $this->pty->getMaster();
    }

    /**
     * Start a command connected to the PTY
     */
    public function start(string $command): int|false
    {
        $this->pty->open();
        $slaveFdNum = $this->pty->getSlaveFd();

        // This is CRITICAL - we must be a session leader to set controlling terminal
        $sid = posix_setsid();
        if ($sid === -1) {
            $errno = posix_get_last_error();
            // If we're already a session leader, this is expected
            if ($errno !== 1) { // 1 is EPERM
                throw new \RuntimeException('Failed to create new session: '.posix_strerror($errno));
            }
        }

        // Set the slave PTY as our controlling terminal
        try {
            $this->pty->getFfi()->setControllingTerminal($slaveFdNum);
        } catch (\Exception $e) {
            // We'll try to continue anyway
        }

        $descriptorSpec = [
            0 => ['file', $this->pty->getSlaveName(), 'r'],
            1 => ['file', $this->pty->getSlaveName(), 'w'],
            2 => ['file', $this->pty->getSlaveName(), 'w'],
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
        stream_set_blocking($this->pty->getMaster(), false);

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
        $this->pty->close();
        parent::stop();
    }
}
