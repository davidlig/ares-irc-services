<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;

use function filter_var;

use const FILTER_VALIDATE_EMAIL;

/**
 * Registers a nickname on behalf of another user, at staff request.
 *
 * Deliberately skips the e-mail verification policy, the verification token and
 * the registration throttle: that is the entire point of the command. It does NOT
 * skip validation — the address still has to be well formed and unused, and the
 * nickname still has to be available.
 */
final readonly class SaregisterHandler implements SaregisterHandlerInterface
{
    public function __construct(
        private RegisterNickRepository $nickRepository,
        private PasswordHasher $passwordHasher,
        private Clock $clock,
        private int $maxNicksPerEmail,
    ) {}

    public function handle(Saregister $command): SaregisterResult
    {
        if (false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
            return SaregisterResult::invalidEmail();
        }

        if (0 < $this->maxNicksPerEmail
            && $this->nickRepository->countByEmail($command->email) >= $this->maxNicksPerEmail
        ) {
            return SaregisterResult::emailAlreadyUsed($command->email, $this->maxNicksPerEmail);
        }

        $existing = $this->nickRepository->findByNick($command->nickname);
        if (null !== $existing) {
            return match ($existing->getStatus()) {
                NickStatus::Pending => SaregisterResult::alreadyPending($command->nickname),
                NickStatus::Forbidden => SaregisterResult::forbidden($command->nickname),
                NickStatus::PendingDeletion => SaregisterResult::pendingDeletion($command->nickname),
                default => SaregisterResult::alreadyRegistered($command->nickname),
            };
        }

        $nick = RegisteredNick::createActive(
            nickname: $command->nickname,
            passwordHash: $this->passwordHasher->hash($command->password),
            email: $command->email,
            language: $command->language,
            registeredAt: $this->clock->now(),
        );
        $this->nickRepository->save($nick);

        return SaregisterResult::registered($command->nickname);
    }
}
