<?php

namespace Whisp;

/**
 * FFI wrapper for PTY operations that handles OS-specific differences
 */
class Ffi
{
    private \FFI $ffi;

    public array $constants;

    public function __construct()
    {
        if (! extension_loaded('ffi')) {
            throw new \RuntimeException('FFI extension not loaded');
        }

        $libcName = PHP_OS === 'Darwin' ? 'libc.dylib' : 'libc.so.6';

        // Define different FFI based on OS
        if (PHP_OS === 'Darwin') {
            $this->ffi = \FFI::cdef('
                #define _DARWIN_C_SOURCE

                // Type definitions
                typedef unsigned int mode_t;
                typedef unsigned long tcflag_t;
                typedef unsigned char cc_t;
                typedef unsigned long speed_t;

                // Function declarations
                int ioctl(int fd, unsigned long request, ...);
                char *strerror(int errnum);
                int *__error(void);
                int tcgetattr(int fd, struct termios *termios_p);
                int tcsetattr(int fd, int optional_actions, const struct termios *termios_p);
                int open(const char *pathname, int flags, ...);
                int close(int fd);
                int fcntl(int fd, int cmd, ...);

                // Type definitions
                typedef char ptsname_t[128];

                // Struct definitions
                struct termios {
                    tcflag_t c_iflag;
                    tcflag_t c_oflag;
                    tcflag_t c_cflag;
                    tcflag_t c_lflag;
                    cc_t     c_cc[20];  // NCCS = 20 on macOS
                    speed_t  c_ispeed;
                    speed_t  c_ospeed;
                };

                struct winsize {
                    unsigned short ws_row;
                    unsigned short ws_col;
                    unsigned short ws_xpixel;
                    unsigned short ws_ypixel;
                };
            ', $libcName);

            // These constants are obtained from running the get_termios_constants program on macOS
            $this->constants = [
                // ioctl requests
                'TIOCPTYGNAME' => 0x40807453,
                'TIOCPTYGRANT' => 0x20007454,
                'TIOCPTYUNLK' => 0x20007452,
                'TIOCSWINSZ' => 0x80087467,
                'TIOCSCTTY' => 0x20007461,  // Set controlling terminal
                'TIOCSPGRP' => 0x80047476,  // Set process group

                // Terminal settings
                'TCSANOW' => 0,

                // Local mode flags (c_lflag)
                'ICANON' => 0x00000002,
                'ECHO' => 0x00000008,
                'ECHOE' => 0x00000002,
                'ECHOK' => 0x00000004,
                'ECHONL' => 0x00000010,
                'ISIG' => 0x00000080,
                'IEXTEN' => 0x00000400,

                // Input mode flags (c_iflag)
                'ICRNL' => 0x00000100,  // Convert CR to NL on input

                // File flags
                'O_RDWR' => 0x0002,
                'O_NOCTTY' => 0x20000,
                'O_NONBLOCK' => 0x0004,   // Non-blocking I/O
                'FIOCLEX' => 0x20006601,  // Set close-on-exec flag
                'FIONCLEX' => 0x20006602, // Clear close-on-exec flag

                // fcntl commands
                'F_GETFL' => 3,
                'F_SETFL' => 4,
                'F_SETFD' => 2,
                'F_GETFD' => 1,
                'FD_CLOEXEC' => 1,        // Close-on-exec flag

                // cc_t array indices
                'VINTR' => 8,
                'VQUIT' => 9,
                'VERASE' => 3,
                'VKILL' => 5,
                'VEOF' => 0,
                'VEOL' => 1,
                'VEOL2' => 2,
                'VSTART' => 12,
                'VSTOP' => 13,
                'VSUSP' => 10,
                'VREPRINT' => 6,
                'VWERASE' => 4,
                'VLNEXT' => 14,
                'VDSUSP' => 11,
                'VSTATUS' => 18,
            ];
        } else {
            // Linux version
            $this->ffi = \FFI::cdef('
                typedef unsigned int mode_t;
                typedef unsigned int tcflag_t;  // 4 bytes on Linux
                typedef unsigned char cc_t;
                typedef unsigned int speed_t;   // 4 bytes on Linux

                // Function declarations
                int ioctl(int fd, unsigned long request, ...);
                char* strerror(int errnum);
                extern int errno;               // Global errno
                int tcgetattr(int fd, struct termios* termios_p);
                int tcsetattr(int fd, int optional_actions, const struct termios* termios_p);
                int open(const char *pathname, int flags, ...);
                int close(int fd);
                int fcntl(int fd, int cmd, ...);

                // Type definitions
                typedef char ptsname_t[128];

                // Struct definitions
                struct termios {
                    tcflag_t c_iflag;          // offset 0
                    tcflag_t c_oflag;          // offset 4
                    tcflag_t c_cflag;          // offset 8
                    tcflag_t c_lflag;          // offset 12
                    cc_t     c_line;           // offset 16
                    cc_t     c_cc[32];         // offset 17, NCCS = 32 on Linux
                    speed_t  c_ispeed;         // offset 49
                    speed_t  c_ospeed;         // offset 53
                };

                struct winsize {
                    unsigned short ws_row;      // offset 0
                    unsigned short ws_col;      // offset 2
                    unsigned short ws_xpixel;   // offset 4
                    unsigned short ws_ypixel;   // offset 6
                };
            ', $libcName);

            $this->constants = [
                // ioctl requests
                'TIOCGPTN' => 0x80045430,    // Get PTY number
                'TIOCSPTLCK' => 0x40045431,  // Lock/unlock PTY
                'TIOCSWINSZ' => 0x5414,      // Correct Linux value
                'TIOCSCTTY' => 0x540E,       // Set controlling terminal
                'TIOCSPGRP' => 0x5410,       // Set process group

                // Terminal settings
                'TCSANOW' => 0,

                // Local mode flags (c_lflag)
                'ISIG' => 0x00000001,
                'ICANON' => 0x00000002,
                'ECHO' => 0x00000008,
                'ECHOE' => 0x00000010,
                'ECHOK' => 0x00000020,
                'ECHONL' => 0x00000040,
                'NOFLSH' => 0x00000080,
                'TOSTOP' => 0x00000100,
                'IEXTEN' => 0x00008000,
                'ECHOCTL' => 0x00000200,
                'ECHOKE' => 0x00000800,
                'PENDIN' => 0x00004000,
                'XCASE' => 0x00000004,

                // Input mode flags (c_iflag)
                'IGNPAR' => 0x00000004,
                'PARMRK' => 0x00000008,
                'INPCK' => 0x00000010,
                'ISTRIP' => 0x00000020,
                'INLCR' => 0x00000040,
                'IGNCR' => 0x00000080,
                'ICRNL' => 0x00000100,
                'IUCLC' => 0x00000200,
                'IXON' => 0x00000400,
                'IXANY' => 0x00000800,
                'IXOFF' => 0x00001000,
                'IMAXBEL' => 0x00002000,
                'IUTF8' => 0x00004000,

                // Output flags (c_oflag)
                'OPOST' => 0x00000001,
                'OLCUC' => 0x00000002,
                'ONLCR' => 0x00000004,
                'OCRNL' => 0x00000008,
                'ONOCR' => 0x00000010,
                'ONLRET' => 0x00000020,

                // Control flags (c_cflag)
                'CS7' => 0x00000020,
                'CS8' => 0x00000030,
                'PARENB' => 0x00000100,
                'PARODD' => 0x00000200,

                // fcntl commands
                'F_GETFL' => 3,
                'F_SETFL' => 4,
                'F_SETFD' => 2,
                'F_GETFD' => 1,
                'FD_CLOEXEC' => 1,

                // File flags
                'O_RDWR' => 0x0002,
                'O_NOCTTY' => 0x100,
                'O_NONBLOCK' => 0x800,

                // cc_t array indices
                'VINTR' => 0,
                'VQUIT' => 1,
                'VERASE' => 2,
                'VKILL' => 3,
                'VEOF' => 4,
                'VEOL' => 11,
                'VEOL2' => 16,
                'VSTART' => 8,
                'VSTOP' => 9,
                'VSUSP' => 10,
                'VREPRINT' => 12,
                'VWERASE' => 14,
                // VDSUSP and VSTATUS are not defined on Linux
            ];
        }
    }

    public function getErrno(): int
    {
        if (PHP_OS === 'Darwin') {
            $errPtr = $this->ffi->__error();

            return $errPtr[0];
        } else {
            // On Linux, errno is a global variable
            return $this->ffi->errno;
        }
    }

    public function getSlaveNameFromMaster(int $masterFd): string
    {
        if (PHP_OS === 'Darwin') {
            // macOS: Use TIOCPTYGNAME to get the slave name
            $namebuf = $this->ffi->new('ptsname_t');
            \FFI::memset($namebuf, 0, 128);

            $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCPTYGNAME'), \FFI::addr($namebuf));
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to get slave name: {$error} (errno: {$errno})");
            }

            $name = \FFI::string($namebuf);

            return $name;
        } else {
            // Linux: Use TIOCGPTN to get PTY number
            $ptnbuf = $this->ffi->new('unsigned int');
            $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCGPTN'), \FFI::addr($ptnbuf));
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to get PTY number: {$error} (errno: {$errno})");
            }

            $name = '/dev/pts/'.$ptnbuf->cdata;

            return $name;
        }
    }

    public function unlockPty(int $masterFd): void
    {
        if (PHP_OS === 'Darwin') {
            $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCPTYUNLK'), 0);
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to unlock PTY: {$error} (errno: {$errno})");
            }
        } else {
            // On Linux, unlock the PTY using TIOCSPTLCK
            $unlockArg = $this->ffi->new('int');
            $unlockArg->cdata = 0;  // 0 = unlock
            $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCSPTLCK'), \FFI::addr($unlockArg));
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to unlock PTY: {$error} (errno: {$errno})");
            }
        }
    }

    public function grantPty(int $masterFd): void
    {
        if (PHP_OS === 'Darwin') {
            $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCPTYGRANT'), 0);
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to grant PTY: {$error} (errno: {$errno})");
            }
        }
        // No equivalent needed for Linux
    }

    public function setWindowSize(int $masterFd, int $rows, int $cols, int $xPixel = 0, int $yPixel = 0): void
    {
        $ws = $this->ffi->new('struct winsize');
        $ws->ws_row = $rows;
        $ws->ws_col = $cols;
        $ws->ws_xpixel = $xPixel;
        $ws->ws_ypixel = $yPixel;

        $ret = $this->ffi->ioctl($masterFd, $this->getConstant('TIOCSWINSZ'), \FFI::addr($ws));
        if ($ret === -1) {
            $errno = $this->getErrno();
            $error = \FFI::string($this->ffi->strerror($errno));
            throw new \RuntimeException("Failed to set window size: {$error} (errno: {$errno})");
        }
    }

    public function getTermios(int $fd)
    {
        $termios = $this->ffi->new('struct termios');
        $result = $this->ffi->tcgetattr($fd, \FFI::addr($termios));

        if ($result === -1) {
            $errno = $this->getErrno();
            $error = \FFI::string($this->ffi->strerror($errno));
            throw new \RuntimeException("Failed to get terminal attributes: {$error} (errno: {$errno})");
        }

        return $termios;
    }

    public function setTermios(int $fd, $termios): void
    {
        $result = $this->ffi->tcsetattr($fd, $this->getConstant('TCSANOW'), \FFI::addr($termios));

        if ($result === -1) {
            $errno = $this->getErrno();
            $error = \FFI::string($this->ffi->strerror($errno));
            throw new \RuntimeException("Failed to set terminal attributes: {$error} (errno: {$errno})");
        }

    }

    /**
     * Set the controlling terminal for the current process
     */
    public function setControllingTerminal(int $fd): bool
    {
        // Log current process info
        $pid = getmypid();
        $pgid = posix_getpgid(0);
        $sid = posix_getsid(0);

        // Directly use the existing FFI instance
        try {
            $tiocscttyCValue = $this->getConstant('TIOCSCTTY');

            // Call ioctl directly with 0 as the data argument
            $result = @$this->ffi->ioctl($fd, $tiocscttyCValue, 0);

            if ($result === -1) {
                return false;
            }


            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set the foreground process group for the terminal
     */
    public function setForegroundProcessGroup(int $fd, int $pid): bool
    {
        // Get process info
        $pgrp = posix_getpgid($pid);
        if ($pgrp === false) {
            return false;
        }

        // If process group is 0, use the PID as the process group
        if ($pgrp === 0) {
            $pgrp = $pid;
        }

        try {
            $tiocspgrpValue = $this->getConstant('TIOCSPGRP');

            // Create an integer to hold the process group ID and pass by reference
            $pgrpPtr = $this->ffi->new('int');
            $pgrpPtr->cdata = $pgrp;

            // Call ioctl with the pgrp data
            $result = @$this->ffi->ioctl($fd, $tiocspgrpValue, \FFI::addr($pgrpPtr));

            if ($result === -1) {
                return false;
            }


            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getConstant(string $name): int
    {
        return $this->constants[$name] ?? 0;
    }

    public function new(string $type)
    {
        return $this->ffi->new($type);
    }

    /**
     * Set O_NOCTTY flag on a file descriptor
     */
    public function setNoctty(int $fd): void
    {
        $flags = $this->getConstant('O_NOCTTY');
        if ($this->ffi->ioctl($fd, $flags, 0) === -1) {
            $errno = $this->getErrno();
            $error = \FFI::string($this->ffi->strerror($errno));
            throw new \RuntimeException("Failed to set O_NOCTTY flag: {$error}");
        }
    }
}
