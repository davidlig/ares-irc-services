<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use LogicException;
use OverflowException;
use Throwable;

use function sprintf;
use function str_starts_with;

use const FILTER_VALIDATE_EMAIL;

final readonly class RegisterNickHandler implements RegisterNickHandlerInterface
{
    public function __construct(
        private EmailDomainPolicy $emailDomainPolicy,
        private RegisterNickRepository $nickRepository,
        private PasswordHasher $passwordHasher,
        private RescueTokenCodec $tokenGenerator,
        private Clock $clock,
        private TemporaryRescueTokenStore $verificationStore,
        private RegistrationModeQuery $registrationMode,
        private RegistrationThrottle $throttle,
        private RegistrationMailSender $mailSender,
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

        $mode = $this->registrationMode->current();
        if ((RegistrationMode::Email === $mode && null === $command->email)
            || (null !== $command->email && false === filter_var($command->email, FILTER_VALIDATE_EMAIL))) {
            return RegisterNickResult::invalidEmail();
        }

        if (null !== $command->email && $this->emailDomainPolicy->isBlocked($command->email)) {
            return RegisterNickResult::blockedEmailDomain();
        }

        if (null !== $command->email && null !== $this->nickRepository->findByEmail($command->email)) {
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

        $hash = $this->tokenGenerator->hash($token) ?? throw new LogicException('Generated rescue token is invalid.');
        $this->nickRepository->save($nick);
        $this->throttle->recordAttempt($command->clientKey, $now);
        $this->verificationStore->prune($now);
        try {
            $this->verificationStore->replace($nick->getId(), $hash, $expiresAt);
        } catch (OverflowException) {
            return RegistrationMode::Token === $mode
                ? RegisterNickResult::temporarilyUnavailable()
                : RegisterNickResult::mailDeliveryFailed();
        }
        if (RegistrationMode::Token === $mode) {
            return RegisterNickResult::tokenVerificationRequired($token);
        }
        try {
            $this->mailSender->sendVerification(
                $command->nickname,
                $command->email ?? throw new LogicException('Email registration requires an address.'),
                $token,
                $command->language,
            );
        } catch (Throwable) {
            return RegisterNickResult::mailDeliveryFailed();
        }

        return RegisterNickResult::verificationRequired($command->email ?? '');
    }
}
