<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a user loses their identified status, remove IRCOP user modes if they had a role assigned.
 */
final readonly class OperRoleModesDeidentifiedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private ActiveConnectionHolder $connectionHolder,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserDeidentifiedEvent::class => ['onUserDeidentified', 0],
        ];
    }

    public function onUserDeidentified(UserDeidentifiedEvent $event): void
    {
        $ircop = $this->ircopRepository->findByNickId($event->nickId);
        if (null === $ircop) {
            return;
        }

        $role = $ircop->getRole();
        $modes = $role->getUserModes();

        if (empty($modes)) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('OperRoleModesDeidentifiedSubscriber: no protocol module');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        $serviceActions = $module->getServiceActions();

        $modesStr = '-' . implode('', $modes);
        $this->logger->info('OperRoleModesDeidentifiedSubscriber: removing modes on deidentify', [
            'nickId' => $event->nickId,
            'uid' => $event->uid,
            'modes' => $modesStr,
        ]);
        $serviceActions->setUserMode($serverSid, $event->uid, $modesStr);

        $this->userLookup->applyModeChange($event->uid, $modesStr);
    }
}
