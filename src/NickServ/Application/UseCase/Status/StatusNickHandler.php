<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Status;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;

use function ceil;
use function max;

final readonly class StatusNickHandler implements StatusNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private Clock $clock,
    ) {}

    public function handle(StatusNick $command): StatusNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account) {
            if (!$command->isOnline) {
                return StatusNickResult::unregisteredOffline($command->nickname);
            }

            return StatusNickResult::unregisteredOnline($command->nickname);
        }

        return match ($account->getStatus()) {
            NickStatus::Pending => $this->handlePending($command->nickname, $account->getExpiresAt()),
            NickStatus::Registered => $this->handleRegistered($command),
            NickStatus::Suspended => StatusNickResult::suspended(
                $command->nickname,
                $account->getReason(),
                $account->getSuspendedUntil(),
            ),
            NickStatus::Forbidden => StatusNickResult::forbidden(
                $command->nickname,
                $account->getReason(),
            ),
            NickStatus::PendingDeletion => StatusNickResult::pendingDeletion(
                $command->nickname,
                $account->getPendingDeletionAt(),
            ),
        };
    }

    private function handlePending(string $nickname, ?DateTimeImmutable $expiresAt): StatusNickResult
    {
        $minutes = 0;
        if (null !== $expiresAt) {
            $now = $this->clock->now();
            $diffSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
            $minutes = max(0, (int) ceil($diffSeconds / 60));
        }

        return StatusNickResult::pending($nickname, $expiresAt, $minutes);
    }

    private function handleRegistered(StatusNick $command): StatusNickResult
    {
        if (!$command->isOnline) {
            return StatusNickResult::registeredNotConnected($command->nickname);
        }

        if (!$command->isIdentified) {
            return StatusNickResult::registeredNotIdentified($command->nickname);
        }

        return StatusNickResult::registeredIdentified($command->nickname);
    }
}
