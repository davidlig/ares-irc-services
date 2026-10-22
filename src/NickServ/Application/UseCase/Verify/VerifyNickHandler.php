<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Domain\ValueObject\NickStatus;

final readonly class VerifyNickHandler implements VerifyNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RescueTokenCodec $codec,
        private TemporaryRescueTokenStore $temporaryTokens,
        private RescueAttemptLimiter $limiter,
        private RescueCredentialPersistence $credentials,
        private IdentifiedSessionTracker $identifiedRegistry,
        private Clock $clock,
        private NicknameAuthenticationModeQuery $authenticationMode,
        private RegistrationEventPublisher $eventPublisher,
    ) {}

    public function handle(VerifyNick $command): VerifyNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account || (!$account->isPending() && !$account->isRegistered())) {
            return VerifyNickResult::noPending();
        }
        $now = $this->clock->now();
        $clientKey = '' !== $command->clientKey ? $command->clientKey : 'uid:' . $command->senderUid;
        $cooldown = $this->limiter->remainingCooldownSeconds($account->getId(), $clientKey, $now);
        if (0 < $cooldown) {
            return VerifyNickResult::lockedOut($cooldown);
        }
        $hash = $this->codec->hash($command->token);
        $expectedHash = $account->getRescueTokenHash();
        $migration = $account->isRegistered();
        $authenticatedNick = $this->identifiedRegistry->findNick($command->senderUid);
        $authenticated = $command->authenticatedAccountId === $account->getId()
            || (null !== $authenticatedNick && 0 === strcasecmp($authenticatedNick, $account->getNickname()));
        $expired = $account->isPending() && (null === $account->getExpiresAt() || $account->getExpiresAt() <= $now);
        $matches = null !== $hash && !$expired && (!$migration || $authenticated)
            && $this->temporaryTokens->matches($account->getId(), $hash, $now);
        if (!$matches) {
            $this->limiter->recordFailure($account->getId(), $clientKey, $now);

            return VerifyNickResult::invalidToken();
        }
        if (!$this->credentials->confirm($account->getId(), $migration ? NickStatus::Registered : NickStatus::Pending, $expectedHash, $hash, $now)) {
            $this->limiter->recordFailure($account->getId(), $clientKey, $now);

            return VerifyNickResult::invalidToken();
        }
        $this->temporaryTokens->removeIfMatches($account->getId(), $hash);
        if ($migration) {
            return VerifyNickResult::credentialConfirmed();
        }

        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            passwordHash: $account->getPasswordHash(),
        ));

        if (NicknameAuthenticationMode::NativeNick === $this->authenticationMode->current()) {
            return VerifyNickResult::successNativeAuthenticationRequired($account->getNickname());
        }

        $this->identifiedRegistry->register($command->senderUid, $account->getNickname());

        return VerifyNickResult::success($account->getNickname());
    }
}
