<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;

use function strtolower;

final readonly class DropNickHandler implements DropNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickTargetValidator $targetValidator,
        private NickDropService $dropService,
    ) {}

    public function handle(DropNick $command): DropNickResult
    {
        if (strtolower($command->targetNick) === strtolower($command->operatorNick)) {
            return DropNickResult::cannotDropSelf();
        }

        $account = $this->nickRepository->findByNick($command->targetNick);
        if (null === $account) {
            return DropNickResult::notRegistered($command->targetNick);
        }

        if ($account->isPendingDeletion()) {
            if ($command->force) {
                if (!$command->forceAllowed) {
                    return DropNickResult::forcePermissionDenied();
                }

                $this->dropService->hardDropNick($account, 'manual-force', $command->operatorNick);

                return DropNickResult::hardDropSuccess($command->targetNick);
            }

            return DropNickResult::pendingDeletion($command->targetNick);
        }

        if ($account->isSuspended()) {
            return DropNickResult::suspended($command->targetNick);
        }

        if ($account->isForbidden()) {
            return DropNickResult::forbidden($command->targetNick);
        }

        $protectResult = $this->targetValidator->validate($command->targetNick);
        if (!$protectResult->isAllowed()) {
            return match ($protectResult->status) {
                NickProtectabilityStatus::IsRoot => DropNickResult::cannotDropRoot($command->targetNick),
                NickProtectabilityStatus::IsIrcop => DropNickResult::cannotDropOper($command->targetNick),
                NickProtectabilityStatus::IsService => DropNickResult::cannotDropService($command->targetNick),
                NickProtectabilityStatus::Allowed => DropNickResult::softDropSuccess($command->targetNick),
            };
        }

        if ($command->force) {
            if (!$command->forceAllowed) {
                return DropNickResult::forcePermissionDenied();
            }

            $this->dropService->hardDropNick($account, 'manual-force', $command->operatorNick);

            return DropNickResult::hardDropSuccess($command->targetNick);
        }

        $this->dropService->softDropNick($account, $command->operatorNick);

        return DropNickResult::softDropSuccess($command->targetNick);
    }
}
