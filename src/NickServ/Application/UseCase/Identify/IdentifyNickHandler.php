<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Identify;

use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\IdentifyEventPublisher;
use App\NickServ\Application\Port\Out\IdentifyLockoutTracker;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Domain\Event\NickIdentifiedEvent;

use function strcasecmp;

final readonly class IdentifyNickHandler implements IdentifyNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private IdentifiedSessionTracker $identifiedRegistry,
        private IdentifyLockoutTracker $lockoutTracker,
        private IdentifyEventPublisher $eventPublisher,
        private VhostDisplayResolver $vhostDisplayResolver,
        private ForcedVhostCheckerInterface $forcedVhostChecker,
        private int $identifyMaxFailedAttempts,
        private int $identifyFailedWindowSeconds,
        private int $identifyLockoutSeconds,
    ) {}

    public function handle(IdentifyNick $command): IdentifyNickResult
    {
        $alreadyTrackedNick = $this->identifiedRegistry->findNick($command->senderUid);
        if (null !== $alreadyTrackedNick && 0 === strcasecmp($alreadyTrackedNick, $command->nickname)) {
            return IdentifyNickResult::alreadyIdentified($command->nickname);
        }

        if ($command->senderIsIdentified && 0 === strcasecmp($command->senderNick, $command->nickname)) {
            $this->identifiedRegistry->register($command->senderUid, $command->nickname);

            return IdentifyNickResult::alreadyIdentified($command->nickname);
        }

        $remaining = $this->lockoutTracker->getRemainingLockoutSeconds(
            $command->clientKey,
            $this->identifyMaxFailedAttempts,
            $this->identifyFailedWindowSeconds,
            $this->identifyLockoutSeconds,
        );

        if ($remaining > 0) {
            return IdentifyNickResult::lockedOut($remaining);
        }

        $account = $this->nickRepository->findByNick($command->nickname);
        if (null === $account) {
            return IdentifyNickResult::notRegistered($command->nickname);
        }

        if ($account->isPending()) {
            return IdentifyNickResult::pending($command->nickname);
        }

        if ($account->isSuspended()) {
            return IdentifyNickResult::suspended($command->nickname, $account->getReason() ?? '');
        }

        if ($account->isForbidden()) {
            return IdentifyNickResult::forbidden($command->nickname);
        }

        if ($account->isPendingDeletion()) {
            return IdentifyNickResult::pendingDeletion($command->nickname);
        }

        if (!$account->verifyPassword($command->password)) {
            $this->lockoutTracker->recordFailedAttempt($command->clientKey, $this->identifyFailedWindowSeconds);

            return IdentifyNickResult::invalidCredentials();
        }

        $this->lockoutTracker->clearFailedAttempts($command->clientKey);
        $account->markSeen();
        $this->nickRepository->save($account);
        $this->identifiedRegistry->register($command->senderUid, $account->getNickname());

        $displayVhost = null;
        if (!$this->forcedVhostChecker->hasForcedVhost($account->getId())) {
            $displayVhost = $this->vhostDisplayResolver->getDisplayVhost($account->getVhost());
        }

        $this->eventPublisher->publish(new NickIdentifiedEvent(
            $account->getId(),
            $account->getNickname(),
            $command->senderUid,
        ));

        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            $account->getId(),
            $account->getNickname(),
            $account->getPasswordHash(),
        ));

        return IdentifyNickResult::success(
            nickname: $account->getNickname(),
            displayVhost: $displayVhost,
            accountLanguage: $account->getLanguage(),
        );
    }
}
