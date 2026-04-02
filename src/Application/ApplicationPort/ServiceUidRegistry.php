<?php

declare(strict_types=1);

namespace App\Application\ApplicationPort;

/**
 * Registry that provides UID lookup for services.
 * Allows resolving a service nickname to its UID for message sending.
 */
final readonly class ServiceUidRegistry
{
    /**
     * @param array<string, ServiceUidProviderInterface> $providers Map of service key => provider
     */
    public function __construct(
        private array $providers = [],
    ) {
    }

    /**
     * Create from iterable of tagged services.
     *
     * @param iterable<ServiceUidProviderInterface> $providerIter
     */
    public static function fromIterable(iterable $providerIter): self
    {
        $providers = [];
        foreach ($providerIter as $provider) {
            $providers[$provider->getServiceKey()] = $provider;
        }

        return new self($providers);
    }

    /**
     * Get the UID for a service by its service key.
     *
     * @param string $serviceKey The service key (e.g., 'nickserv', 'chanserv')
     *
     * @return string|null The UID, or null if not found
     */
    public function getUid(string $serviceKey): ?string
    {
        $provider = $this->providers[$serviceKey] ?? null;
        if (null === $provider) {
            return null;
        }

        return $provider->getUid();
    }

    /**
     * Get the UID for a service by its nickname (case-insensitive).
     *
     * @param string $nickname The service nickname (e.g., 'NickServ', 'ChanServ')
     *
     * @return string|null The UID, or null if not found
     */
    public function getUidByNickname(string $nickname): ?string
    {
        $nicknameLower = strtolower($nickname);
        foreach ($this->providers as $provider) {
            if (strtolower($provider->getNickname()) === $nicknameLower) {
                return $provider->getUid();
            }
        }

        return null;
    }
}
