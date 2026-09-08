<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Protocol;

use App\NickServ\Application\Port\Out\NicknameReservation;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;

final readonly class ProtocolNicknameReservation implements NicknameReservation
{
    public function __construct(private ActiveConnectionHolderInterface $connectionHolder) {}

    public function reserve(string $nickname, string $reason): void
    {
        $this->connectionHolder->getProtocolModule()?->getNickReservation()?->reserveNick($nickname, $reason);
    }

    public function release(string $nickname): void
    {
        $this->connectionHolder->getProtocolModule()?->getNickReservation()?->releaseNick($nickname);
    }
}
