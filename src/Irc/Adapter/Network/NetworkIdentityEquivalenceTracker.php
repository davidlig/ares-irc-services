<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Application\Port\In\NetworkIdentityEquivalence;
use App\Irc\Domain\ValueObject\CaseMapping;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Follows the negotiated casemapping; CaseMappingSessionSubscriber restores ASCII on each link change. */
final class NetworkIdentityEquivalenceTracker implements NetworkIdentityEquivalence, EventSubscriberInterface
{
    private CaseMapping $mapping = CaseMapping::Ascii;

    public static function getSubscribedEvents(): array
    {
        return [CaseMappingChangedEvent::class => 'onCaseMappingChanged'];
    }

    public function onCaseMappingChanged(CaseMappingChangedEvent $event): void
    {
        $this->mapping = $event->mapping;
    }

    public function fold(string $value): string
    {
        return $this->mapping->fold($value);
    }

    public function equivalent(string $left, string $right): bool
    {
        return $this->mapping->fold($left) === $this->mapping->fold($right);
    }
}
