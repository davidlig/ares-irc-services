<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Unsuspend;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;

final readonly class UnsuspendNickHandler
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickServEventPublisher $eventPublisher,
        private Clock $clock,
    ) {}

    public function handle(UnsuspendNick $input): UnsuspendNickResult
    {
        $account = $this->nickRepository->findByNick($input->targetNickname);
        if (null === $account) {
            return new UnsuspendNickResult(UnsuspendNickOutcome::NotRegistered, $input->targetNickname);
        }

        if (!$account->isSuspended()) {
            return new UnsuspendNickResult(UnsuspendNickOutcome::NotSuspended, $input->targetNickname);
        }

        $account->unsuspend();
        $this->nickRepository->save($account);
        $this->eventPublisher->publish(new NickUnsuspendedEvent(
            nickId: $account->getId(),
            nickname: $input->targetNickname,
            performedBy: $input->actor->nickname,
            performedByNickId: $input->actor->accountId,
            performedByIp: $input->actor->ip,
            performedByHost: $input->actor->host,
            occurredAt: $this->clock->now(),
        ));

        return new UnsuspendNickResult(UnsuspendNickOutcome::Unsuspended, $input->targetNickname);
    }
}
