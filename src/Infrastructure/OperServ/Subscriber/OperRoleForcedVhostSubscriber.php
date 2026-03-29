<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\OperServ\ForcedVhostApplier;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class OperRoleForcedVhostSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ForcedVhostApplier $vhostApplier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NickIdentifiedEvent::class => ['onNickIdentified', 0],
        ];
    }

    public function onNickIdentified(NickIdentifiedEvent $event): void
    {
        $this->vhostApplier->applyForcedVhostIfApplicable(
            $event->nickId,
            $event->nickname,
            $event->uid,
        );
    }
}
