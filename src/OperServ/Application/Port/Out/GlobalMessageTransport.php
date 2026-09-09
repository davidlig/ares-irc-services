<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;

/** Network capability for broadcasting GLOBAL messages without exposing IRCd protocol details. */
interface GlobalMessageTransport
{
    public function serviceUidForNickname(string $nickname): ?string;

    /** Returns null when the network is unavailable, otherwise the recipient count. */
    public function broadcastFromService(string $senderUid, string $message, MessageDelivery $delivery): ?int;

    /** Returns null when the network is unavailable, otherwise the recipient count. */
    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, MessageDelivery $delivery, string $actorNickname): ?int;
}
