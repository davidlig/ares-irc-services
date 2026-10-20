<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

interface GlineNetworkActions
{
    public function add(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void;

    public function remove(string $mask): void;

    /**
     * Adds a G-line whose mask is sent verbatim to the IRC daemon.
     *
     * The mask is not split into a user and a host part, so it may be an extban
     * such as ~country:ES.
     */
    public function addByMask(string $mask, string $reason): void;

    /**
     * Removes a G-line whose mask is sent verbatim to the IRC daemon.
     *
     * The mask is not split into a user and a host part, so it may be an extban
     * such as ~country:ES.
     */
    public function removeByMask(string $mask): void;
}
