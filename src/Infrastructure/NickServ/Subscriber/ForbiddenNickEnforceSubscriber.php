<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\NickServ\BurstState;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final readonly class ForbiddenNickEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private ForbiddenNickService $forbiddenService,
        private BurstState $burstState,
        private PendingNickRestoreRegistryInterface $pendingRegistry,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserNickChangedEvent::class => ['onNickChanged', 10],
            UserJoinedNetworkAppEvent::class => ['onUserJoined', 10],
        ];
    }

    public function onNickChanged(UserNickChangedEvent $event): void
    {
        if (!$this->burstState->isComplete()) {
            return;
        }

        if ($this->pendingRegistry->peek($event->uid->value)) {
            $this->logger->debug(sprintf(
                'ForbiddenNickEnforce: skipping nick change for %s (pending restore)',
                $event->uid->value,
            ));

            return;
        }

        $this->enforceForbidden($event->newNick->value, $event->uid->value);
    }

    public function onUserJoined(UserJoinedNetworkAppEvent $event): void
    {
        if (!$this->burstState->isComplete()) {
            return;
        }

        $this->enforceForbidden($event->user->nick, $event->user->uid);
    }

    private function enforceForbidden(string $nick, string $uid): void
    {
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isForbidden()) {
            return;
        }

        $user = $this->userLookup->findByUid($uid);

        if (null === $user) {
            return;
        }

        $reason = $account->getReason() ?? '';

        $this->logger->info(sprintf(
            'ForbiddenNickEnforce: User %s [%s] using forbidden nick %s. Forcing rename.',
            $nick,
            $uid,
            $nick,
        ));

        $this->forbiddenService->notifyAndForceGuest($uid, $reason, $nick);
    }
}
