<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use LogicException;
use Throwable;

use const FILTER_VALIDATE_EMAIL;

/**
 * Administrative nickname registration on behalf of a third party.
 *
 * The account is created already active: no pending verification state exists in either mode.
 * The rescue credential is attached to the account before it is persisted, so the account and its
 * credential are written in a single insert and can never be left half-created.
 */
final readonly class SaregisterNickHandler implements SaregisterNickHandlerInterface
{
    public function __construct(
        private RegisterNickRepository $nickRepository,
        private PasswordHasher $passwordHasher,
        private RescueTokenCodec $tokenCodec,
        private Clock $clock,
        private RegistrationModeQuery $registrationMode,
        private RegistrationMailSender $mailSender,
    ) {}

    public function handle(SaregisterNick $command): SaregisterNickResult
    {
        $now = $this->clock->now();
        $mode = $this->registrationMode->current();

        if (null !== $command->email && false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
            return SaregisterNickResult::invalidEmail($command->nickname, $mode, $command->email);
        }

        if (RegistrationMode::Email === $mode && null === $command->email) {
            return SaregisterNickResult::invalidEmail($command->nickname, $mode, null);
        }

        if (null !== $command->email && null !== $this->nickRepository->findByEmail($command->email)) {
            return SaregisterNickResult::emailAlreadyUsed($command->nickname, $mode, $command->email);
        }

        $existing = $this->nickRepository->findByNick($command->nickname);
        if (null !== $existing) {
            return match ($existing->getStatus()) {
                NickStatus::Pending => SaregisterNickResult::alreadyPending($command->nickname, $mode),
                NickStatus::Forbidden => SaregisterNickResult::forbidden($command->nickname, $mode),
                NickStatus::PendingDeletion => SaregisterNickResult::pendingDeletion($command->nickname, $mode),
                default => SaregisterNickResult::alreadyRegistered($command->nickname, $mode),
            };
        }

        $token = $this->tokenCodec->generate();
        $hash = $this->tokenCodec->hash($token) ?? throw new LogicException('Generated rescue token is invalid.');

        $nick = RegisteredNick::createActive(
            nickname: $command->nickname,
            passwordHash: $this->passwordHasher->hash($command->password),
            email: $command->email,
            language: $command->language,
            registeredAt: $now,
        );
        $nick->replaceRescueTokenHash($hash);
        $this->nickRepository->save($nick);

        if (RegistrationMode::Token === $mode) {
            return SaregisterNickResult::registeredWithToken($command->nickname, $mode, $token, $command->email);
        }

        try {
            $this->mailSender->sendVerification(
                $command->nickname,
                $command->email ?? throw new LogicException('Email registration requires an address.'),
                $token,
                $command->language,
            );
        } catch (Throwable) {
            return SaregisterNickResult::registeredWithoutMail(
                $command->nickname,
                $mode,
                $command->email ?? '',
            );
        }

        return SaregisterNickResult::registered($command->nickname, $mode, $command->email ?? '');
    }
}
