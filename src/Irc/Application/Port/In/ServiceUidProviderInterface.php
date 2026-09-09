<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Interface for services that can provide their UID on the IRC network.
 * Services that need to act as message sources implement this interface.
 */
interface ServiceUidProviderInterface
{
    /**
     * Get the UID of this service on the IRC network.
     */
    public function getUid(): string;

    /**
     * Get the service key (e.g. 'nickserv', 'chanserv').
     */
    public function getServiceKey(): string;

    /**
     * Get the service nickname.
     */
    public function getNickname(): string;
}
