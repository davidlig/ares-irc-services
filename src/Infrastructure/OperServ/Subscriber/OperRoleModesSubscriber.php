<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a user identifies with NickServ, apply IRCOP user modes if they have a role assigned.
 */
final readonly class OperRoleModesSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private IdentifiedSessionRegistry $identifiedRegistry,
        private ActiveConnectionHolder $connectionHolder,
        private LoggerInterface $logger,
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
        // Find if this nickId has an IRCOP role assigned
        $ircop = $this->ircopRepository->findByNickId($event->nickId);
        if (null === $ircop) {
            return;
        }

        $role = $ircop->getRole();
        $modes = $role->getUserModes();

        if (empty($modes)) {
            return;
        }

        // Apply modes via SVSMODE
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('OperRoleModesSubscriber: no protocol module');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        $serviceActions = $module->getServiceActions();

        $modesStr = '+' . implode('', $modes);
        $this->logger->info('OperRoleModesSubscriber: applying modes on identify', ['nickId' => $event->nickId, 'uid' => $event->uid, 'modes' => $modesStr]);
        $serviceActions->setUserMode($serverSid, $event->uid, $modesStr);
    }
}
