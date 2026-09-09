<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Interface for services that expose a configurable nickname.
 * Services implement this to allow dynamic service name resolution in translations.
 */
interface ServiceNicknameProviderInterface
{
    /**
     * Get the service key used for placeholder resolution (e.g., 'nickserv', 'chanserv').
     * This is the lowercase key used in translations: %nickserv%, %chanserv%, etc.
     */
    public function getServiceKey(): string;

    /**
     * Get the configured nickname for this service.
     */
    public function getNickname(): string;
}
