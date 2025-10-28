<?php

namespace Whisp;

use Whisp\Concerns\WritesLogs;
use Whisp\Enums\TerminalMode;
use Whisp\Values\WinSize;

/**
 * A PHP implementation of a PTY (pseudo-terminal) manager
 *
 * Handles opening a master/slave PTY pair and managing terminal settings
 */
class Pty
{
    use WritesLogs;

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

    private ?array $environment = [];

    private $process = null; // Process resource

    private $pipes = []; // Process pipes

    private bool $commandRunning = false;

    public ?int $childPid = null;

    public function __construct()
    {
        if (! $this->isSupported()) {
            throw new \RuntimeException('PTY support requires /dev/ptmx');
        }

        $this->ffi = new Ffi;
    }

    public function getFfi(): Ffi
    {
        return $this->ffi;
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

        // Open master PTY (TODO? with O_RDWR|O_CLOEXEC like creack/pty)
        $master = fopen('/dev/ptmx', 'r+');
        if ($master === false) {
            throw new \RuntimeException('Failed to open master PTY');
        }

        try {
            // Get master file descriptor
            $this->masterFd = $this->getFileDescriptor('/dev/ptmx');

            // Get slave name using OS-specific method
            $this->slaveName = $this->ffi->getSlaveNameFromMaster($this->masterFd);

            // Grant and unlock PTY (only grant on macOS)
            if (PHP_OS === 'Darwin') {
                $this->ffi->grantPty($this->masterFd);
            }

            $this->ffi->unlockPty($this->masterFd);

            $slave = fopen($this->slaveName, 'r+');
            // Store the resources
            $this->master = $master;
            $this->slave = $slave; // Store the raw file descriptor

            // Make master stream non-blocking
            stream_set_blocking($this->master, false);

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

        fflush($this->master);

        return $written;
    }

    public function read(int $length = 2048): string
    {
        if (! $this->master || ! is_resource($this->master)) {
            return '';
        }

        if ($length <= 0) {
            return '';
        }

        $resp = fread($this->master, $length) ?: '';

        return $resp;
    }

    public function close(): void
    {
        if ($this->slave !== null) {
            fclose($this->slave);
            $this->slave = null;
        }

        if ($this->master && is_resource($this->master)) {
            fclose($this->master);
            $this->master = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->master !== null && is_resource($this->master) &&
            $this->slave !== null;
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

    /**
     * Get the file descriptor for the slave PTY
     */
    public function getSlaveFd(): int
    {
        return $this->getFileDescriptor($this->slaveName);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Set terminal window size
     */
    public function setWindowSize(WinSize $size): void
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
     * Get the correct c_cc index for a terminal control character
     */
    public function getCcIndex(string $name): int
    {
        if (! isset($this->ffi->constants[$name])) {
            throw new \RuntimeException("Terminal control character '$name' not supported on this platform");
        }

        return $this->ffi->getConstant($name);
    }

    /**
     * Helper method to set or clear a flag in a termios field
     */
    private function setFlag(\FFI\CData &$field, int $flag, bool $value): void
    {
        if ($value) {
            $field->cdata |= $flag;
        } else {
            $field->cdata &= ~$flag;
        }
    }

    /**
     * Setup terminal with the given modes
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

        $fd = $this->getSlaveFd();

        // Set window size
        $this->setWindowSize(new WinSize($heightRows, $widthChars, $widthPixels, $heightPixels));

        if (! $this->ffi) {
            return;
        }

        // Get current terminal settings
        $termios = $this->ffi->getTermios($fd);

        // Set up basic terminal flags first
        $termios->c_lflag |= $this->ffi->getConstant('ISIG');   // Enable signals
        $termios->c_lflag |= $this->ffi->getConstant('ICANON'); // Enable canonical mode
        $termios->c_lflag |= $this->ffi->getConstant('ECHO');   // Enable echo
        $termios->c_lflag |= $this->ffi->getConstant('ECHOE');  // Echo erase
        $termios->c_lflag |= $this->ffi->getConstant('ECHOK');  // Echo kill
        $termios->c_lflag |= $this->ffi->getConstant('ECHONL'); // Echo NL
        $termios->c_lflag |= $this->ffi->getConstant('IEXTEN'); // Enable extensions

        // Enable ICRNL in input flags to translate CR to NL

        $termios->c_iflag |= $this->ffi->getConstant('ICRNL');

        // Disable OPOST in output flags to prevent post-processing
        $termios->c_oflag &= ~$this->ffi->getConstant('OPOST');

        // Apply SSH client's terminal modes
        foreach ($modes as $opcode => $value) {
            try {
                // Handle special values first
                if ($opcode === TerminalMode::TTY_OP_ISPEED->value) {
                    $termios->c_ispeed = $value;

                    continue;
                }
                if ($opcode === TerminalMode::TTY_OP_OSPEED->value) {
                    $termios->c_ospeed = $value;

                    continue;
                }
                if ($opcode === TerminalMode::TTY_OP_END->value) {
                    break;
                }

                // Map control characters
                $ccIndex = match ($opcode) {
                    TerminalMode::VINTR->value => $this->getCcIndex('VINTR'),
                    TerminalMode::VQUIT->value => $this->getCcIndex('VQUIT'),
                    TerminalMode::VERASE->value => $this->getCcIndex('VERASE'),
                    TerminalMode::VKILL->value => $this->getCcIndex('VKILL'),
                    TerminalMode::VEOF->value => $this->getCcIndex('VEOF'),
                    TerminalMode::VEOL->value => $this->getCcIndex('VEOL'),
                    TerminalMode::VEOL2->value => $this->getCcIndex('VEOL2'),
                    TerminalMode::VSTART->value => $this->getCcIndex('VSTART'),
                    TerminalMode::VSTOP->value => $this->getCcIndex('VSTOP'),
                    TerminalMode::VSUSP->value => $this->getCcIndex('VSUSP'),
                    TerminalMode::VREPRINT->value => $this->getCcIndex('VREPRINT'),
                    TerminalMode::VWERASE->value => $this->getCcIndex('VWERASE'),
                    TerminalMode::VLNEXT->value => $this->getCcIndex('VLNEXT'),
                    // Platform specific mappings
                    TerminalMode::VDSUSP->value => PHP_OS === 'Darwin' ? $this->getCcIndex('VDSUSP') : null,
                    TerminalMode::VSTATUS->value => PHP_OS === 'Darwin' ? $this->getCcIndex('VSTATUS') : null,
                    default => null
                };

                if ($ccIndex !== null) {
                    $termios->c_cc[$ccIndex] = $value;

                    continue;
                }

                // Handle input flags
                match ($opcode) {
                    TerminalMode::IGNPAR->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IGNPAR'), $value),
                    TerminalMode::PARMRK->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('PARMRK'), $value),
                    TerminalMode::INPCK->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('INPCK'), $value),
                    TerminalMode::ISTRIP->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('ISTRIP'), $value),
                    TerminalMode::INLCR->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('INLCR'), $value),
                    TerminalMode::IGNCR->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IGNCR'), $value),
                    TerminalMode::ICRNL->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('ICRNL'), $value),
                    TerminalMode::IUCLC->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IUCLC'), $value),
                    TerminalMode::IXON->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IXON'), $value),
                    TerminalMode::IXANY->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IXANY'), $value),
                    TerminalMode::IXOFF->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IXOFF'), $value),
                    TerminalMode::IMAXBEL->value => $this->setFlag($termios->c_iflag, $this->ffi->getConstant('IMAXBEL'), $value),

                    // Handle local flags
                    TerminalMode::ISIG->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ISIG'), $value),
                    TerminalMode::ICANON->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ICANON'), $value),
                    TerminalMode::XCASE->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('XCASE'), $value),
                    TerminalMode::ECHO->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHO'), $value),
                    TerminalMode::ECHOE->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHOE'), $value),
                    TerminalMode::ECHOK->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHOK'), $value),
                    TerminalMode::ECHONL->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHONL'), $value),
                    TerminalMode::NOFLSH->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('NOFLSH'), $value),
                    TerminalMode::TOSTOP->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('TOSTOP'), $value),
                    TerminalMode::IEXTEN->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('IEXTEN'), $value),
                    TerminalMode::ECHOCTL->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHOCTL'), $value),
                    TerminalMode::ECHOKE->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('ECHOKE'), $value),
                    TerminalMode::PENDIN->value => $this->setFlag($termios->c_lflag, $this->ffi->getConstant('PENDIN'), $value),

                    // Handle output flags
                    // OPOST is always disabled - do not allow client to enable it
                    TerminalMode::OLCUC->value => $this->setFlag($termios->c_oflag, $this->ffi->getConstant('OLCUC'), $value),
                    TerminalMode::ONLCR->value => $this->setFlag($termios->c_oflag, $this->ffi->getConstant('ONLCR'), $value),
                    TerminalMode::OCRNL->value => $this->setFlag($termios->c_oflag, $this->ffi->getConstant('OCRNL'), $value),
                    TerminalMode::ONOCR->value => $this->setFlag($termios->c_oflag, $this->ffi->getConstant('ONOCR'), $value),
                    TerminalMode::ONLRET->value => $this->setFlag($termios->c_oflag, $this->ffi->getConstant('ONLRET'), $value),

                    // Handle control flags
                    TerminalMode::CS7->value => $this->setFlag($termios->c_cflag, $this->ffi->getConstant('CS7'), $value),
                    TerminalMode::CS8->value => $this->setFlag($termios->c_cflag, $this->ffi->getConstant('CS8'), $value),
                    TerminalMode::PARENB->value => $this->setFlag($termios->c_cflag, $this->ffi->getConstant('PARENB'), $value),
                    TerminalMode::PARODD->value => $this->setFlag($termios->c_cflag, $this->ffi->getConstant('PARODD'), $value),
                    default => null
                };
            } catch (\RuntimeException $e) {
                continue;
            }
        }

        // Apply the modified terminal settings
        $this->ffi->setTermios($fd, $termios);
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

    /**
     * Set an environment variable for the command
     */
    public function setEnvironmentVariable(string $name, string $value): void
    {
        $this->environment[$name] = $value;
    }

    /**
     * Get the environment variables for the command
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /**
     * Check if a command is currently running
     */
    public function isCommandRunning(): bool
    {
        return $this->commandRunning;
    }

    /**
     * Get the process resource
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * Get the child process ID
     */
    public function getChildPid(): ?int
    {
        return $this->childPid;
    }
}
