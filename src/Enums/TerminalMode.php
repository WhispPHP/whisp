<?php

declare(strict_types=1);

namespace Whisp\Enums;

enum TerminalMode: int
{
    // RFC 4254: The Secure Shell (SSH) Protocol - https://www.ietf.org/rfc/rfc4254.txt
    // Control characters
    case VINTR = 1;        // Interrupt character
    case VQUIT = 2;        // Quit character
    case VERASE = 3;       // Erase character
    case VKILL = 4;        // Kill character
    case VEOF = 5;         // End-of-file character
    case VEOL = 6;         // End-of-line character
    case VEOL2 = 7;        // Second end-of-line character
    case VSTART = 8;       // Start character
    case VSTOP = 9;        // Stop character
    case VSUSP = 10;       // Suspend character
    case VDSUSP = 11;      // Delayed suspend character
    case VREPRINT = 12;    // Reprint character
    case VWERASE = 13;     // Word erase character
    case VLNEXT = 14;      // Literal next character
    case VFLUSH = 15;      // Flush character
    case VSWTCH = 16;      // Switch character
    case VSTATUS = 17;     // Status character
    case VDISCARD = 18;    // Discard character

    // Input flags
    case IGNPAR = 30;      // Ignore parity errors
    case PARMRK = 31;      // Mark parity errors
    case INPCK = 32;       // Enable input parity check
    case ISTRIP = 33;      // Strip 8th bit off characters
    case INLCR = 34;       // Map NL to CR on input
    case IGNCR = 35;       // Ignore CR
    case ICRNL = 36;       // Map CR to NL on input
    case IUCLC = 37;       // Map uppercase to lowercase
    case IXON = 38;        // Enable start/stop output control
    case IXANY = 39;       // Enable any character to restart output
    case IXOFF = 40;       // Enable start/stop input control
    case IMAXBEL = 41;     // Ring bell when input queue is full

    // Local flags
    case ISIG = 50;        // Enable signals
    case ICANON = 51;      // Canonical input (erase and kill processing)
    case XCASE = 52;       // Canonical upper/lower presentation
    case ECHO = 53;        // Enable echo
    case ECHOE = 54;       // Echo erase character as BS-SP-BS
    case ECHOK = 55;       // Echo NL after kill character
    case ECHONL = 56;      // Echo NL
    case NOFLSH = 57;      // Disable flush after interrupt/quit
    case TOSTOP = 58;      // Send SIGTTOU for background output
    case IEXTEN = 59;      // Enable extended input processing
    case ECHOCTL = 60;     // Echo control characters as ^X
    case ECHOKE = 61;      // Visual erase for line kill
    case PENDIN = 62;      // Retype pending input at next read

    // Output flags
    case OPOST = 70;       // Enable output processing
    case OLCUC = 71;       // Map lowercase to uppercase
    case ONLCR = 72;       // Map NL to CR-NL
    case OCRNL = 73;       // Map CR to NL
    case ONOCR = 74;       // No CR output at column 0
    case ONLRET = 75;      // NL performs CR function

    // Control flags
    case CS7 = 90;         // 7 bit mode
    case CS8 = 91;         // 8 bit mode
    case PARENB = 92;      // Parity enable
    case PARODD = 93;      // Odd parity

    // Special values
    case TTY_OP_ISPEED = 128;  // Input speed
    case TTY_OP_OSPEED = 129;  // Output speed
    case TTY_OP_END = 0;       // End of options
}
