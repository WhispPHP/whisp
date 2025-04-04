<?php

declare(strict_types=1);

namespace Whisp\Enums;

enum MessageType: int
{
    // SSH Message Types
    case DISCONNECT = 1;

    case IGNORE = 2;

    case UNIMPLEMENTED = 3;

    case DEBUG = 4;

    case SERVICE_REQUEST = 5;

    case SERVICE_ACCEPT = 6;

    case EXT_INFO = 7; // RFC 8308

    // Key Exchange Messages
    case KEXINIT = 20;

    case NEWKEYS = 21;

    case KEXDH_INIT = 30;

    case KEXDH_REPLY = 31;

    // User Authentication Messages
    case USERAUTH_REQUEST = 50;

    case USERAUTH_FAILURE = 51;

    case USERAUTH_SUCCESS = 52;

    case USERAUTH_BANNER = 53;

    // case USERAUTH_INFO_REQUEST = 60; // same number as USERAUTH_PK_OK but different context (RFC 4252)

    case USERAUTH_INFO_RESPONSE = 61;

    case USERAUTH_PK_OK = 60;

    // Connection Protocol Messages
    case GLOBAL_REQUEST = 80;

    case REQUEST_SUCCESS = 81;

    case REQUEST_FAILURE = 82;

    case CHANNEL_OPEN = 90;

    case CHANNEL_OPEN_CONFIRMATION = 91;

    case CHANNEL_OPEN_FAILURE = 92;

    case CHANNEL_WINDOW_ADJUST = 93;

    case CHANNEL_DATA = 94;

    case CHANNEL_EXTENDED_DATA = 95;

    case CHANNEL_EOF = 96;

    case CHANNEL_CLOSE = 97;

    case CHANNEL_REQUEST = 98;

    case CHANNEL_SUCCESS = 99;

    case CHANNEL_FAILURE = 100;

    public static function chr(MessageType $value): string
    {
        return chr($value->value);
    }
}
