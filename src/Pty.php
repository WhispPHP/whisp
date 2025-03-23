<?php

namespace Whisp;

use Whisp\Values\WinSize;

/**
 * A PHP implementation of a PTY (pseudo-terminal) manager
 *
 * Handles opening a master/slave PTY pair and managing terminal settings
 */
class Pty
{
    // Terminal mode constants
    private const VINTR = 0;

    private const VQUIT = 1;

    private const VERASE = 2;

    private const VKILL = 3;

    private const VEOF = 4;

    private const ICRNL = 0x00000100;

    /**
     * @var resource|null
     */
    private $master;

    /**
     * @var resource|null
     */
    private $slave;

    private string $slaveName;

    private ?Ffi $ffi;

    public int $masterFd;

    public function __construct()
    {
        if (! $this->isSupported()) {
            throw new \RuntimeException('PTY support requires /dev/ptmx');
        }

        $this->ffi = new Ffi;
    }

    /**
     * Get the file descriptor for a given path
     * Required to call various FFI PTY functions
     */
    private function getFileDescriptor($path): int
    {
        if (PHP_OS !== 'Darwin' && file_exists('/proc/self/fd')) {
            // Linux: Use /proc/self/fd method
            $fds = @scandir('/proc/self/fd');
            if ($fds === false) {
                throw new \RuntimeException('Failed to scan /proc/self/fd');
            }

            foreach ($fds as $fd) {
                if ($fd === '.' || $fd === '..') {
                    continue;
                }
                $link = @readlink("/proc/self/fd/$fd");
                if ($link === $path) {
                    return (int) $fd;
                }
            }
        }

        if (PHP_OS === 'Darwin') {
            // macOS: Use lsof method as fstat doesn't work
            $cmd = sprintf("lsof -n -p %d | grep -F %s | tail -n1 | awk '{print $4}' | cut -d'u' -f1", getmypid(), escapeshellarg($path));
            $fd = (int) trim(shell_exec($cmd));
            if ($fd > 0) {
                return $fd;
            }
        }

        // TODO: Can we use an FFI function here that works across all platforms?

        throw new \RuntimeException("Failed to get file descriptor for {$path}");
    }

    public function open(): array
    {
        if ($this->master) {
            return [$this->master, $this->slave];
        }

        // Open master PTY
        $master = fopen('/dev/ptmx', 'r+');
        if ($master === false) {
            throw new \RuntimeException('Failed to open master PTY');
        }

        try {
            // Get master file descriptor using lsof
            $this->masterFd = $this->getFileDescriptor('/dev/ptmx');

            // Get slave name using OS-specific method
            $this->slaveName = $this->ffi->getSlaveNameFromMaster($this->masterFd);

            // Grant and unlock PTY
            if (PHP_OS === 'Darwin') { // Only need to grant on MacOS
                $this->ffi->grantPty($this->masterFd);
            }
            $this->ffi->unlockPty($this->masterFd);

            // Open slave PTY
            $slave = fopen($this->slaveName, 'r+');
            if ($slave === false) {
                throw new \RuntimeException('Failed to open slave PTY');
            }

            // Store the resources
            $this->master = $master;
            $this->slave = $slave;

            // Set raw mode on both master and slave
            $slaveFd = $this->getFileDescriptor($this->slaveName);
            $this->ffi->setRawMode($slaveFd);

            // Make both streams non-blocking
            stream_set_blocking($this->master, false);
            stream_set_blocking($this->slave, false);

            return [$this->master, $this->slave];
        } catch (\Exception $e) {
            if (isset($master)) {
                fclose($master);
            }
            if (isset($slave)) {
                fclose($slave);
            }
            $this->master = null;
            $this->slave = null;
            throw $e;
        }
    }

    public function write(string $data): int
    {
        if (! $this->master || ! is_resource($this->master)) {
            return 0;
        }

        $written = @fwrite($this->master, $data);
        if ($written === false) {
            return 0;
        }

        return $written;
    }

    public function read(int $length = 1024): string
    {
        if (! $this->master || ! is_resource($this->master)) {
            return '';
        }

        if ($length <= 0) {
            return '';
        }

        return fread($this->master, $length) ?: '';
    }

    public function close(): void
    {
        if ($this->slave) {
            fclose($this->slave);
            $this->slave = null;
        }
        if ($this->master) {
            fclose($this->master);
            $this->master = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->master !== null && is_resource($this->master) &&
            $this->slave !== null && is_resource($this->slave);
    }

    public function getMaster()
    {
        return $this->master;
    }

    public function getSlave()
    {
        return $this->slave;
    }

    public function getSlaveName(): string
    {
        return $this->slaveName;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Set terminal window size
     */
    public function setWindowSize(Winsize $size): void
    {
        if (! $this->ffi) {
            // Fallback to stty if FFI not available
            $command = sprintf(
                'stty rows %d cols %d < %s',
                $size->rows,
                $size->cols,
                escapeshellarg($this->slaveName)
            );
            shell_exec($command);

            return;
        }

        $this->ffi->setWindowSize(
            $this->masterFd,
            $size->rows,
            $size->cols,
            $size->widthPixels,
            $size->heightPixels
        );
    }

    /**
     * Get terminal window size
     */
    public function getWindowSize(): Winsize
    {
        // Run stty size to get rows/cols
        $output = shell_exec('stty size < '.escapeshellarg($this->slaveName));
        if (! $output) {
            return new Winsize(24, 80); // Default fallback
        }

        $parts = explode(' ', trim($output));
        if (count($parts) !== 2) {
            return new Winsize(24, 80); // Default fallback
        }

        return new Winsize((int) $parts[0], (int) $parts[1]);
    }

    /**
     * Setup terminal from SSH pty-req
     */
    public function setupTerminal(
        string $term,
        int $widthChars,
        int $heightRows,
        int $widthPixels,
        int $heightPixels,
        array $modes
    ): void {
        if (! $this->master || ! $this->slave) {
            throw new \RuntimeException('PTY not opened');
        }

        // Set window size
        $this->setWindowSize(new Winsize($heightRows, $widthChars, $widthPixels, $heightPixels));

        if (! $this->ffi) {
            return;
        }

        // Apply terminal modes
        $termios = $this->ffi->getTermios($this->masterFd);
        foreach ($modes as $opcode => $value) {
            // Handle common terminal modes
            switch ($opcode) {
                case 1: // VINTR
                    $termios->c_cc[self::VINTR] = $value;
                    break;
                case 2: // VQUIT
                    $termios->c_cc[self::VQUIT] = $value;
                    break;
                case 3: // VERASE
                    $termios->c_cc[self::VERASE] = $value;
                    break;
                case 4: // VKILL
                    $termios->c_cc[self::VKILL] = $value;
                    break;
                case 5: // VEOF
                    $termios->c_cc[self::VEOF] = $value;
                    break;
                case 17: // ICRNL
                    if ($value) {
                        $termios->c_iflag |= self::ICRNL;
                    } else {
                        $termios->c_iflag &= ~self::ICRNL;
                    }
                    break;
            }
        }

        // Apply the modified termios settings
        $this->ffi->setTermios($this->masterFd, $termios);
    }

    /**
     * Check if a PTY is supported on this system
     */
    public function isSupported(): bool
    {
        // Check for required device
        if (! file_exists('/dev/ptmx')) {
            return false;
        }

        // Check for required POSIX functions
        $requiredFunctions = [
            'posix_ttyname',
            'posix_isatty',
            'posix_geteuid',
            'posix_getegid',
        ];

        foreach ($requiredFunctions as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return true;
    }
}
