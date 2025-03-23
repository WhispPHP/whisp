<?php

namespace Whisp;

/**
 * FFI wrapper for PTY operations that handles OS-specific differences
 */
class Ffi
{
    private \FFI $ffi;

    private array $constants;

    public function __construct()
    {
        if (! extension_loaded('ffi')) {
            throw new \RuntimeException('FFI extension not loaded');
        }

        $libcName = PHP_OS === 'Darwin' ? 'libc.dylib' : 'libc.so.6';

        // Define different FFI based on OS
        if (PHP_OS === 'Darwin') {
            $this->ffi = \FFI::cdef('
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

                // Type definitions
                typedef char ptsname_t[128];

                // Struct definitions
                struct termios {
                    tcflag_t c_iflag;
                    tcflag_t c_oflag;
                    tcflag_t c_cflag;
                    tcflag_t c_lflag;
                    cc_t     c_cc[20];
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

            $this->constants = [
                'TIOCPTYGNAME' => 0x40807453,
                'TIOCPTYGRANT' => 0x20007454,
                'TIOCPTYUNLK' => 0x20007452,
                'TCSANOW' => 0,
                'ICANON' => 0x00000100,
                'ECHO' => 0x00000008,
                'ECHOE' => 0x00000002,
                'ECHOK' => 0x00000004,
                'ECHONL' => 0x00000010,
                'ISIG' => 0x00000080,
                'IEXTEN' => 0x00000400,
                'ICRNL' => 0x00000100,  // Convert CR to NL on input
                'TIOCSWINSZ' => 0x80087467,
            ];
        } else {
            // Linux version - with correct struct layout and constants from test output
            $this->ffi = \FFI::cdef('
                typedef unsigned int mode_t;
                typedef unsigned int tcflag_t;  // 4 bytes on Linux
                typedef unsigned char cc_t;
                typedef unsigned int speed_t;   // 4 bytes on Linux

                // Function declarations
                int ioctl(int fd, unsigned long request, void* arg);
                char* strerror(int errnum);
                extern int errno;               // Global errno
                int tcgetattr(int fd, struct termios* termios_p);
                int tcsetattr(int fd, int optional_actions, const struct termios* termios_p);

                // Type definitions
                typedef char ptsname_t[128];

                // Struct definitions
                #pragma pack(1)  // Important: ensure correct struct packing
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
                #pragma pack()  // Reset packing
            ', $libcName);

            $this->constants = [
                'TIOCGPTN' => 0x80045430,    // Get PTY number
                'TIOCSPTLCK' => 0x40045431,  // Lock/unlock PTY
                'TCSANOW' => 0,
                'ICANON' => 0x2,             // Updated Linux values
                'ECHO' => 0x8,
                'ECHOE' => 0x10,
                'ECHOK' => 0x20,
                'ECHONL' => 0x40,
                'ISIG' => 0x1,
                'IEXTEN' => 0x8000,
                'ICRNL' => 0x100,
                'TIOCSWINSZ' => 0x5414,      // Correct Linux value
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

            $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCPTYGNAME'], \FFI::addr($namebuf));
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to get slave name: {$error} (errno: {$errno})");
            }

            return \FFI::string($namebuf);
        } else {
            // Linux: Use TIOCGPTN to get PTY number
            $ptnbuf = $this->ffi->new('unsigned int');
            $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCGPTN'], \FFI::addr($ptnbuf));
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to get PTY number: {$error} (errno: {$errno})");
            }

            return '/dev/pts/'.$ptnbuf->cdata;
        }
    }

    public function unlockPty(int $masterFd): void
    {
        if (PHP_OS === 'Darwin') {
            $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCPTYUNLK'], 0);
            if ($ret === -1) {
                $errno = $this->getErrno();
                $error = \FFI::string($this->ffi->strerror($errno));
                throw new \RuntimeException("Failed to unlock PTY: {$error} (errno: {$errno})");
            }
        } else {
            // On Linux, unlock the PTY using TIOCSPTLCK
            $unlockArg = $this->ffi->new('int');
            $unlockArg->cdata = 0;  // 0 = unlock
            $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCSPTLCK'], \FFI::addr($unlockArg));
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
            $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCPTYGRANT'], 0);
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

        $ret = $this->ffi->ioctl($masterFd, $this->constants['TIOCSWINSZ'], \FFI::addr($ws));
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
        $result = $this->ffi->tcsetattr($fd, $this->constants['TCSANOW'], \FFI::addr($termios));

        if ($result === -1) {
            $errno = $this->getErrno();
            $error = \FFI::string($this->ffi->strerror($errno));
            throw new \RuntimeException("Failed to set terminal attributes: {$error} (errno: {$errno})");
        }
    }

    public function setRawMode(int $fd): void
    {
        $termios = $this->getTermios($fd);

        // Turn off canonical mode and echo
        $termios->c_lflag &= ~($this->constants['ICANON'] | $this->constants['ECHO'] |
            $this->constants['ECHOE'] | $this->constants['ECHOK'] |
            $this->constants['ECHONL'] | $this->constants['IEXTEN']);

        // Enable ICRNL in input flags to translate CR to NL
        $termios->c_iflag |= $this->constants['ICRNL'];

        $this->setTermios($fd, $termios);
    }

    public function getConstant(string $name): int
    {
        return $this->constants[$name] ?? 0;
    }

    public function new(string $type)
    {
        return $this->ffi->new($type);
    }
}
