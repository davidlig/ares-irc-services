<?php

declare(strict_types=1);

namespace App\Application\ApplicationPort;

/**
 * Registry that collects all services implementing ServiceNicknameProviderInterface.
 * Allows dynamic resolution of service nicknames for translation placeholders.
 *
 * Services are registered via Symfony tagged services and keyed by their service key.
 */
final readonly class ServiceNicknameRegistry
{
    /** @var array<string, string> Map of service key => nickname (e.g., 'nickserv' => 'NickServ') */
    private array $nicknames;

    /**
     * @param iterable<ServiceNicknameProviderInterface> $providers Tagged services implementing the interface
     */
    public function __construct(iterable $providers)
    {
        $nicknames = [];
        foreach ($providers as $provider) {
            $nicknames[$provider->getServiceKey()] = $provider->getNickname();
        }
        $this->nicknames = $nicknames;
    }

    /**
     * Get the nickname for a service key.
     *
     * @param string $serviceKey The service key (e.g., 'nickserv', 'chanserv')
     *
     * @return string|null The configured nickname, or null if not found
     */
    public function getNickname(string $serviceKey): ?string
    {
        return $this->nicknames[$serviceKey] ?? null;
    }

    /**
     * Check if a service key is registered.
     */
    public function has(string $serviceKey): bool
    {
        return isset($this->nicknames[$serviceKey]);
    }

    /**
     * Get all service placeholders for translation.
     * Returns array like ['%bot%' => 'NickServ', '%nickserv%' => 'NickServ', '%chanserv%' => 'ChanServ', ...].
     *
     * @param string $botNickname The nickname of the current service (for %bot% placeholder)
     *
     * @return array<string, string> Translation placeholders
     */
    public function getAllPlaceholders(string $botNickname): array
    {
        $placeholders = ['%bot%' => $botNickname];
        foreach ($this->nicknames as $key => $nickname) {
            $placeholders['%' . $key . '%'] = $nickname;
        }

        return $placeholders;
    }
}
