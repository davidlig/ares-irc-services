<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

use App\NickServ\Application\Event\NickRecoveredEvent;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryMailSender;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RecoveryTokenStore;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use LogicException;
use OverflowException;
use Throwable;

use function sprintf;

use const FILTER_VALIDATE_EMAIL;

final readonly class RecoverNickHandler implements RecoverNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasher $passwordHasher,
        private RecoveryPasswordGenerator $passwordGenerator,
        private RescueTokenCodec $tokenCodec,
        private RescueCredentialPersistence $credentials,
        private RecoverEventPublisher $eventPublisher,
        private Clock $clock,
        private RegistrationModeQuery $registrationMode,
        private RecoveryTokenStore $recoveryTokens,
        private RecoveryMailSender $mailSender,
        private RescueAttemptLimiter $limiter,
        private TemporaryRescueTokenStore $temporaryTokens,
        private int $recoverTokenTtlSeconds = 3600,
        private int $recoverMinIntervalSeconds = 600,
    ) {}

    public function handle(RecoverNick $command): RecoverNickResult
    {
        $mode = $this->registrationMode->current();
        if (null === $command->token && RegistrationMode::Token === $mode) {
            return RecoverNickResult::instructions($command->nickname);
        }
        $account = $this->nickRepository->findByNick($command->nickname);
        if (null === $account) {
            return RecoverNickResult::notRegistered($command->nickname);
        }
        if ($account->isPending()) {
            return RecoverNickResult::pending($command->nickname);
        }
        if ($account->isSuspended()) {
            return RecoverNickResult::suspended($command->nickname, $account->getReason() ?? '');
        }
        if ($account->isForbidden()) {
            return RecoverNickResult::forbidden($command->nickname);
        }
        if ($account->isPendingDeletion()) {
            return RecoverNickResult::pendingDeletion($command->nickname);
        }
        $expectedHash = $account->getRescueTokenHash();
        $occurredAt = $this->clock->now();
        $remaining = $this->limiter->remainingCooldownSeconds($account->getId(), $command->clientKey, $occurredAt);
        if (0 < $remaining) {
            return RecoverNickResult::throttled($remaining);
        }
        $providedHash = null === $command->token ? null : $this->tokenCodec->hash($command->token);
        $confirmed = null !== $providedHash && null !== $expectedHash && hash_equals($expectedHash, $providedHash);
        $provisional = !$confirmed && null !== $providedHash
            && $this->recoveryTokens->matches($command->nickname, $command->token, $occurredAt);
        if (!$confirmed && !$provisional) {
            if (null !== $command->token) {
                $this->limiter->recordFailure($account->getId(), $command->clientKey, $occurredAt);
                $remaining = $this->limiter->remainingCooldownSeconds($account->getId(), $command->clientKey, $occurredAt);
                if (0 < $remaining) {
                    return RecoverNickResult::throttled($remaining);
                }
            }

            return RegistrationMode::Email === $mode
                ? $this->requestEmailToken($command, $account, $occurredAt)
                : (null === $expectedHash ? RecoverNickResult::credentialUnavailable($command->nickname) : RecoverNickResult::invalidToken($command->nickname));
        }
        $newPassword = $this->passwordGenerator->generate();
        $newPasswordHash = $this->passwordHasher->hash($newPassword);
        $newToken = $confirmed ? $this->tokenCodec->generate() : $command->token;
        $newTokenHash = $this->tokenCodec->hash($newToken);
        if (null === $newTokenHash) {
            throw new LogicException('The generated rescue token is invalid.');
        }
        if (!$this->credentials->rotateAfterRecovery($account->getId(), $expectedHash, $newPasswordHash, $providedHash, $occurredAt)) {
            return RecoverNickResult::invalidToken($command->nickname);
        }
        $replacementUnavailable = false;
        if ($confirmed) {
            $this->temporaryTokens->prune($occurredAt);
            try {
                $this->temporaryTokens->replace($account->getId(), $newTokenHash, $occurredAt->modify(sprintf('+%d seconds', $this->recoverTokenTtlSeconds)));
            } catch (OverflowException) {
                $newToken = null;
                $replacementUnavailable = true;
            }
        } else {
            $this->recoveryTokens->removeIfMatches($command->nickname, $command->token);
            $this->temporaryTokens->discard($account->getId());
        }
        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: $account->getId(),
            nickname: $command->nickname,
            passwordHash: $newPasswordHash,
        ));
        $this->eventPublisher->publish(new NickRecoveredEvent(
            nickId: $account->getId(),
            nickname: $command->nickname,
            method: 'token',
            performedBy: $command->senderNick ?? '',
            performedByNickId: $command->senderAccountId,
            performedByIp: $command->senderIp ?? '*',
            performedByHost: $command->senderHost ?? '',
            occurredAt: $occurredAt,
        ));

        return RecoverNickResult::passwordReset($command->nickname, $newPassword, $newToken, $provisional, $replacementUnavailable);
    }

    private function requestEmailToken(RecoverNick $command, RegisteredNick $account, DateTimeImmutable $now): RecoverNickResult
    {
        $email = $account->getEmail();
        if (null === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return RecoverNickResult::noEmail($command->nickname);
        }
        $last = $this->recoveryTokens->getLastRecoverAt($command->nickname);
        if (null !== $last && $last->getTimestamp() + $this->recoverMinIntervalSeconds > $now->getTimestamp()) {
            return RecoverNickResult::throttled($last->getTimestamp() + $this->recoverMinIntervalSeconds - $now->getTimestamp());
        }
        $token = $this->tokenCodec->generate();
        try {
            $this->recoveryTokens->store($command->nickname, $token, $now->modify(sprintf('+%d seconds', $this->recoverTokenTtlSeconds)));
            // Reserve the bounded timestamp slot before external delivery.
            $this->recoveryTokens->recordRecover($command->nickname, $now);
        } catch (OverflowException) {
            return RecoverNickResult::temporarilyUnavailable();
        }
        try {
            $this->mailSender->sendRecovery($command->nickname, $email, $token, $account->getLanguage());
        } catch (Throwable) {
            return RecoverNickResult::mailDeliveryFailed();
        }

        return RecoverNickResult::tokenSent($email);
    }
}
