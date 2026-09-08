<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

enum SendGlobalMessageOutcome: string
{
    case Sent = 'sent';
    case InvalidMessageType = 'invalid_message_type';
    case InvalidMask = 'invalid_mask';
    case NicknameConnected = 'nickname_connected';
    case NicknameRegistered = 'nickname_registered';
    case NetworkUnavailable = 'network_unavailable';
}
