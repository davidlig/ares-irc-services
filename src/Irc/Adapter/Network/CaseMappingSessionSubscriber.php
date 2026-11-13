<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Domain\ValueObject\CaseMapping;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Negotiated settings belong to a link, never to the daemon's lifetime. */
final readonly class CaseMappingSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablishedEvent::class => ['reset', 256],
            ConnectionLostEvent::class => 'reset',
        ];
    }

    public function reset(): void
    {
        $this->dispatcher->dispatch(new CaseMappingChangedEvent(CaseMapping::Ascii));
    }
}
