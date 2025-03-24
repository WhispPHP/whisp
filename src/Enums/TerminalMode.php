<?php

declare(strict_types=1);

namespace Whisp\Enums;

enum TerminalMode: int
{
    // Control characters (0-17)
    case VINTR = 0;      // Ctrl+C (SIGINT)
    case VQUIT = 1;      // Ctrl+\
    case VERASE = 2;     // Backspace
    case VKILL = 3;      // Ctrl+U
    case VEOF = 4;       // Ctrl+D
    case VEOL = 5;       // End of line
    case VEOL2 = 6;      // Second end of line
    case VSTART = 7;     // Ctrl+Q
    case VSTOP = 8;      // Ctrl+S
    case VSUSP = 9;      // Ctrl+Z
    case VDSUSP = 10;    // Delayed suspend
    case VREPRINT = 11;  // Reprint line
    case VWERASE = 12;   // Word erase
    case VLNEXT = 13;    // Literal next
    case VFLUSH = 14;    // Flush output
    case VSWTCH = 15;    // Switch case
    case VSTATUS = 16;   // Status request
    case VDISCARD = 17;  // Discard pending output

    // Input processing modes (18-24)
    case ICRNL = 18;     // Convert CR to NL
    case INLCR = 19;     // Convert NL to CR
    case IGNCR = 20;     // Ignore CR
    case ISTRIP = 21;    // Strip 8th bit
    case INLCR2 = 22;    // Convert NL to CR
    case IGNCR2 = 23;    // Ignore CR
    case IUCLC = 24;     // Map upper to lower

    // Output processing modes (25-28)
    case IXON = 25;      // Enable output flow control
    case IXANY = 26;     // Any char will restart output
    case IXOFF = 27;     // Enable input flow control
    case IMAXBEL = 28;   // Ring bell on input queue full

    // Local modes (29-40)
    case ISIG = 29;      // Enable signals
    case ICANON = 30;    // Canonical input
    case XCASE = 31;     // Canonical upper/lower
    case ECHO = 32;      // Enable echo
    case ECHOE = 33;     // Echo erase
    case ECHOK = 34;     // Echo kill
    case ECHONL = 35;    // Echo NL
    case NOFLSH = 36;    // Don't flush after interrupt
    case TOSTOP = 37;    // Stop background jobs from output
    case IEXTEN = 38;    // Enable extensions
    case ECHOCTL = 39;   // Echo control chars
    case ECHOKE = 40;    // Visual erase for kill
    case PENDIN = 41;    // Retype pending input

    // Output modes (42-49)
    case OPOST = 42;     // Enable output processing
    case OLCUC = 43;     // Convert lower to upper
    case ONLCR = 44;     // Map NL to CR-NL
    case OCRNL = 45;     // Map CR to NL
    case ONOCR = 46;     // No CR output at column 0
    case ONLRET = 47;    // NL performs CR function

    // Character size and parity modes (48-53)
    case CS7 = 48;       // 7 bit mode
    case CS8 = 49;       // 8 bit mode
    case PARENB = 50;    // Parity enable
    case PARODD = 51;    // Odd parity

    // Terminal speed modes (128-129)
    case TTY_OP_ISPEED = 128;  // Input speed
    case TTY_OP_OSPEED = 129;  // Output speed
}
