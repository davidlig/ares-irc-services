<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Suspend;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickSuspensionService;
use App\NickServ\Application\Service\NickTargetValidator;
use App\Shared\Application\Time\RelativeExpiryParser;

final readonly class SuspendNickHandler
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickTargetValidator $targetValidator,
        private NickSuspensionService $suspensionService,
        private NickServEventPublisher $eventPublisher,
        private Clock $clock,
    ) {}

    public function handle(SuspendNick $input): SuspendNickResult
    {
        $account = $this->nickRepository->findByNick($input->targetNickname);
        if (null === $account) {
            return $this->result(SuspendNickOutcome::NotRegistered, $input);
        }

        if ($account->isForbidden()) {
            return $this->result(SuspendNickOutcome::Forbidden, $input);
        }

        if ($account->isSuspended()) {
            return $this->result(SuspendNickOutcome::AlreadySuspended, $input);
        }

        $protectability = $this->targetValidator->validate($input->targetNickname);
        if (!$protectability->isAllowed()) {
            $outcome = match ($protectability->status) {
                NickProtectabilityStatus::IsRoot => SuspendNickOutcome::TargetIsRoot,
                NickProtectabilityStatus::IsIrcop => SuspendNickOutcome::TargetIsIrcop,
                default => SuspendNickOutcome::TargetIsService,
            };

            return $this->result($outcome, $input);
        }

        $now = $this->clock->now();
        $expiresAt = RelativeExpiryParser::parse($input->duration, $now);
        if (null === $expiresAt && !RelativeExpiryParser::isPermanent($input->duration)) {
            return $this->result(SuspendNickOutcome::InvalidDuration, $input);
        }

        $account->suspend($input->reason, $expiresAt);
        $this->nickRepository->save($account);
        $this->suspensionService->enforceSuspension($account);
        $this->eventPublisher->publish(new NickSuspendedEvent(
            nickId: $account->getId(),
            nickname: $input->targetNickname,
            reason: $input->reason,
            duration: RelativeExpiryParser::isPermanent($input->duration) ? null : $input->duration,
            expiresAt: $expiresAt,
            performedBy: $input->actor->nickname,
            performedByNickId: $input->actor->accountId,
            performedByIp: $input->actor->ip,
            performedByHost: $input->actor->host,
            occurredAt: $now,
        ));

        return new SuspendNickResult(
            SuspendNickOutcome::Suspended,
            $input->targetNickname,
            $input->duration,
            $input->reason,
            $expiresAt,
        );
    }

    private function result(SuspendNickOutcome $outcome, SuspendNick $input): SuspendNickResult
    {
        return new SuspendNickResult($outcome, $input->targetNickname, $input->duration, $input->reason);
    }
}
