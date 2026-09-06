<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\ValueObject\NickStatus;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use Throwable;

use function sprintf;
use function str_starts_with;

use const FILTER_VALIDATE_EMAIL;

final readonly class RegisterNickHandler implements RegisterNickHandlerInterface
{
    public function __construct(
        private RegisterNickRepository $nickRepository,
        private PasswordHasher $passwordHasher,
        private VerificationTokenGenerator $tokenGenerator,
        private Clock $clock,
        private RegistrationVerificationStore $verificationStore,
        private RegistrationThrottle $throttle,
        private RegistrationMailSender $mailSender,
        private RegistrationEventPublisher $eventPublisher,
        private int $minimumIntervalSeconds,
        private int $verificationTokenTtlSeconds = 3600,
        private string $guestPrefix = 'Guest-',
    ) {}

    public function handle(RegisterNick $command): RegisterNickResult
    {
        $now = $this->clock->now();
        $remaining = $this->throttle->remainingCooldownSeconds(
            $command->clientKey,
            $this->minimumIntervalSeconds,
            $now,
        );

        if (0 < $remaining) {
            return RegisterNickResult::throttled($remaining);
        }

        if (str_starts_with($command->nickname, $this->guestPrefix)) {
            return RegisterNickResult::guestPrefixForbidden($this->guestPrefix);
        }

        if (false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
            return RegisterNickResult::invalidEmail();
        }

        if (null !== $this->nickRepository->findByEmail($command->email)) {
            return RegisterNickResult::emailAlreadyUsed($command->email);
        }

        $existing = $this->nickRepository->findByNick($command->nickname);
        if (null !== $existing) {
            return match ($existing->getStatus()) {
                NickStatus::Pending => RegisterNickResult::alreadyPending($command->nickname),
                NickStatus::Forbidden => RegisterNickResult::forbidden($command->nickname),
                NickStatus::PendingDeletion => RegisterNickResult::pendingDeletion($command->nickname),
                default => RegisterNickResult::alreadyRegistered($command->nickname),
            };
        }

        $passwordHash = $this->passwordHasher->hash($command->password);
        $token = $this->tokenGenerator->generate();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->verificationTokenTtlSeconds));
        $nick = RegisteredNick::createPending(
            nickname: $command->nickname,
            passwordHash: $passwordHash,
            email: $command->email,
            language: $command->language,
            expiresAt: $expiresAt,
            registeredAt: $now,
        );

        $this->nickRepository->save($nick);
        $this->verificationStore->store($command->nickname, $token, $expiresAt);
        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: null,
            nickname: $command->nickname,
            passwordHash: $passwordHash,
        ));

        try {
            $this->mailSender->sendVerification(
                $command->nickname,
                $command->email,
                $token,
                $command->language,
            );
        } catch (Throwable) {
            return RegisterNickResult::mailDeliveryFailed();
        }

        $this->throttle->recordAttempt($command->clientKey, $now);

        return RegisterNickResult::verificationRequired($command->email);
    }
}
