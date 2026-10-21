<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

use App\NickServ\Application\Event\NickRecoveredEvent;
use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidator;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use LogicException;

final readonly class RecoverNickHandler implements RecoverNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasher $passwordHasher,
        private RecoveryPasswordGenerator $passwordGenerator,
        private RescueTokenValidator $tokenValidator,
        private RescueTokenCodec $tokenCodec,
        private RescueCredentialPersistence $credentials,
        private RecoverEventPublisher $eventPublisher,
        private Clock $clock,
    ) {}

    public function handle(RecoverNick $command): RecoverNickResult
    {
        if (null === $command->token) {
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
        // Capture before validation: a concurrent rotation must invalidate this write.
        $expectedHash = $account->getRescueTokenHash();
        $validation = $this->tokenValidator->validate(new RescueTokenValidation(
            $account->getId(),
            $command->token,
            $command->clientKey,
        ));
        if (RescueTokenValidationOutcome::Throttled === $validation->outcome) {
            return RecoverNickResult::throttled($validation->retryAfterSeconds);
        }
        if (RescueTokenValidationOutcome::Unavailable === $validation->outcome || null === $expectedHash) {
            return RecoverNickResult::credentialUnavailable($command->nickname);
        }
        if (RescueTokenValidationOutcome::Valid !== $validation->outcome) {
            return RecoverNickResult::invalidToken($command->nickname);
        }
        $newPassword = $this->passwordGenerator->generate();
        $newPasswordHash = $this->passwordHasher->hash($newPassword);
        $newToken = $this->tokenCodec->generate();
        $newTokenHash = $this->tokenCodec->hash($newToken);
        if (null === $newTokenHash) {
            throw new LogicException('The generated rescue token is invalid.');
        }
        $occurredAt = $this->clock->now();
        if (!$this->credentials->rotateAfterRecovery($account->getId(), $expectedHash, $newPasswordHash, $newTokenHash, $occurredAt)) {
            return RecoverNickResult::invalidToken($command->nickname);
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

        return RecoverNickResult::passwordReset($command->nickname, $newPassword, $newToken);
    }
}
