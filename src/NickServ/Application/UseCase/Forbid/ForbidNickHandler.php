<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Forbid;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;

final readonly class ForbidNickHandler
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickTargetValidator $targetValidator,
        private ForbiddenNickService $forbiddenService,
        private NickDropService $dropService,
        private Clock $clock,
    ) {}

    public function handle(ForbidNick $input): ForbidNickResult
    {
        $protectability = $this->targetValidator->validate($input->targetNickname);
        if (!$protectability->isAllowed()) {
            $outcome = match ($protectability->status) {
                NickProtectabilityStatus::IsRoot => ForbidNickOutcome::TargetIsRoot,
                NickProtectabilityStatus::IsIrcop => ForbidNickOutcome::TargetIsIrcop,
                default => ForbidNickOutcome::TargetIsService,
            };

            return new ForbidNickResult($outcome, $protectability->nickname, $input->reason);
        }

        $account = $this->nickRepository->findByNick($input->targetNickname);
        if (null !== $account && $account->isForbidden()) {
            $this->forbiddenService->updateReason($account, $input->reason);

            return new ForbidNickResult(ForbidNickOutcome::ReasonUpdated, $input->targetNickname, $input->reason);
        }

        if (null !== $account) {
            $this->dropService->dropNick($account, $this->clock->now(), 'forbid', $input->actorNickname);
        }

        $this->forbiddenService->forbid($input->targetNickname, $input->reason, $input->actorNickname);

        return new ForbidNickResult(ForbidNickOutcome::Forbidden, $input->targetNickname, $input->reason);
    }
}
