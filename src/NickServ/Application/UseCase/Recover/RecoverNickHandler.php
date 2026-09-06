<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryMailSender;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RecoveryTokenStore;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickPasswordChangedEvent;
use Throwable;

use function assert;
use function sprintf;

final readonly class RecoverNickHandler implements RecoverNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasher $passwordHasher,
        private VerificationTokenGenerator $tokenGenerator,
        private RecoveryPasswordGenerator $passwordGenerator,
        private RecoveryTokenStore $tokenStore,
        private RecoveryMailSender $mailSender,
        private RecoverEventPublisher $eventPublisher,
        private Clock $clock,
        private int $recoverTokenTtlSeconds = 3600,
        private int $recoverMinIntervalSeconds = 300,
    ) {}

    public function handle(RecoverNick $command): RecoverNickResult
    {
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

        if (null === $command->token) {
            return $this->requestToken($command, $account);
        }

        return $this->consumeToken($command, $account);
    }

    private function requestToken(RecoverNick $command, RegisteredNick $account): RecoverNickResult
    {
        $email = $account->getEmail();
        if (null === $email || '' === $email) {
            return RecoverNickResult::noEmail($command->nickname);
        }

        $now = $this->clock->now();
        $lastRecoverAt = $this->tokenStore->getLastRecoverAt($command->nickname);

        if (null !== $lastRecoverAt && 0 < $this->recoverMinIntervalSeconds) {
            $nextAllowedAt = $lastRecoverAt->modify(sprintf('+%d seconds', $this->recoverMinIntervalSeconds));
            if ($now < $nextAllowedAt) {
                return RecoverNickResult::throttled($nextAllowedAt->getTimestamp() - $now->getTimestamp());
            }
        }

        $token = $this->tokenGenerator->generate();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->recoverTokenTtlSeconds));
        $this->tokenStore->store($command->nickname, $token, $expiresAt);

        try {
            $this->mailSender->sendRecovery(
                $command->nickname,
                $email,
                $token,
                $account->getLanguage(),
            );
        } catch (Throwable) {
            return RecoverNickResult::mailDeliveryFailed();
        }

        $this->tokenStore->recordRecover($command->nickname);

        return RecoverNickResult::tokenSent($email);
    }

    private function consumeToken(RecoverNick $command, RegisteredNick $account): RecoverNickResult
    {
        assert(null !== $command->token);

        if (!$this->tokenStore->consume($command->nickname, $command->token)) {
            return RecoverNickResult::invalidToken($command->nickname);
        }

        $newPassword = $this->passwordGenerator->generate();
        $account->changePassword($this->passwordHasher->hash($newPassword));
        $this->nickRepository->save($account);

        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: $account->getId(),
            nickname: $command->nickname,
            passwordHash: $account->getPasswordHash(),
        ));

        $this->eventPublisher->publish(new NickPasswordChangedEvent(
            nickId: $account->getId(),
            nickname: $command->nickname,
            changedByOwner: true,
            performedBy: $command->senderNick ?? '',
            performedByNickId: $command->senderAccountId,
            performedByIp: $command->senderIp ?? '*',
            performedByHost: $command->senderHost ?? '',
        ));

        return RecoverNickResult::passwordReset($command->nickname, $newPassword);
    }
}
