<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a user identifies with NickServ, apply IRCOP user modes if they have a role assigned.
 */
final readonly class OperRoleModesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private IrcopModeApplier $modeApplier,
        private IrcopOperclassApplier $operclassApplier,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickIdentifiedEvent::class => ['onNickIdentified', 0],
        ];
    }

    public function onNickIdentified(NickIdentifiedEvent $event): void
    {
        $ircop = $this->ircopRepository->findByNickId($event->nickId);
        if (null === $ircop) {
            return;
        }

        $role = $ircop->getRole();
        if (!empty($role->getUserModes())) {
            $this->modeApplier->applyModesForNick($event->nickname, $role);
        }

        $this->operclassApplier->applyForNick($event->nickname, $role);
    }
}
