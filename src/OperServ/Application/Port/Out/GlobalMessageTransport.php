<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\UseCase\Global\GlobalMessageType;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;

/** Network capability for broadcasting GLOBAL messages without exposing IRCd protocol details. */
interface GlobalMessageTransport
{
    public function serviceUidForNickname(string $nickname): ?string;

    /** Returns null when the network is unavailable, otherwise the recipient count. */
    public function broadcastFromService(string $senderUid, string $message, GlobalMessageType $messageType): ?int;

    /** Returns null when the network is unavailable, otherwise the recipient count. */
    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, GlobalMessageType $messageType, string $actorNickname): ?int;
}
