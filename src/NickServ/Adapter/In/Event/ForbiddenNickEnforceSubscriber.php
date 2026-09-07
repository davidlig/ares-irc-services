<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
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
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserNicknameChangedEvent::class => ['onNickChanged', 10],
        ];
    }

    public function onNickChanged(UserNicknameChangedEvent $event): void
    {
        if (!$this->burstState->isComplete()) {
            return;
        }

        if ($this->pendingRegistry->peek($event->uid)) {
            $this->logger->debug(sprintf(
                'ForbiddenNickEnforce: skipping nick change for %s (pending restore)',
                $event->uid,
            ));

            return;
        }

        $this->enforceForbidden($event->newNickname, $event->uid);
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
