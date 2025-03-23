<?php

declare(strict_types=1);

namespace Whisp\Enums;

enum DisconnectReason: int
{
    case HOST_NOT_ALLOWED_TO_CONNECT = 1;
    case PROTOCOL_ERROR = 2;
    case KEY_EXCHANGE_FAILED = 3;
    case RESERVED = 4;
    case MAC_ERROR = 5;
    case COMPRESSION_ERROR = 6;
    case SERVICE_NOT_AVAILABLE = 7;
    case PROTOCOL_VERSION_NOT_SUPPORTED = 8;
    case HOST_KEY_NOT_VERIFIABLE = 9;
    case CONNECTION_LOST = 10;
    case DISCONNECT_BY_APPLICATION = 11;
    case TOO_MANY_CONNECTIONS = 12;
    case AUTH_CANCELLED_BY_USER = 13;
    case NO_MORE_AUTH_METHODS_AVAILABLE = 14;
    case ILLEGAL_USER_NAME = 15;
}
