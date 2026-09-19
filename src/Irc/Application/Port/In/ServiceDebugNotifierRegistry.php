<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

final class ServiceDebugNotifierRegistry
{
    /** @var array<string, ServiceDebugNotifierInterface> */
    private array $notifiers = [];

    /**
     * @param iterable<ServiceDebugNotifierInterface> $notifierIter
     */
    public function __construct(iterable $notifierIter)
    {
        foreach ($notifierIter as $notifier) {
            $this->notifiers[$notifier->getServiceName()] = $notifier;
        }
    }

    public function get(string $serviceName): ?ServiceDebugNotifierInterface
    {
        return $this->notifiers[$serviceName] ?? null;
    }
}
