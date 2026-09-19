<?php

declare(strict_types=1);

namespace App\Irc\Adapter\ServiceBridge;

/** Persistent history and write-ahead intentions for Ares nick reservations, not proof of current wire-line ownership. */
interface ServiceNickReservationInventory
{
    /** @return list<string> */
    public function namesForProtocol(string $protocol): array;

    /** @param list<string> $nicknames */
    public function replaceForProtocol(string $protocol, array $nicknames): void;
}
