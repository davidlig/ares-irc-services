<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Rename;

use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;

final readonly class RenameNickHandler implements RenameNickHandlerInterface
{
    public function __construct(
        private NickTargetValidator $targetValidator,
        private NickForceService $forceService,
        private string $guestPrefix = 'Guest-',
    ) {}

    public function handle(RenameNick $command): RenameNickResult
    {
        if (null === $command->targetUid) {
            return RenameNickResult::notOnline($command->targetNick);
        }

        $protectability = $this->targetValidator->validate($command->targetNick);

        if (!$protectability->isAllowed()) {
            return match ($protectability->status) {
                NickProtectabilityStatus::IsRoot => RenameNickResult::cannotRenameRoot($command->targetNick),
                NickProtectabilityStatus::IsIrcop => RenameNickResult::cannotRenameOper($command->targetNick),
                NickProtectabilityStatus::IsService => RenameNickResult::cannotRenameService($command->targetNick),
                default => RenameNickResult::notOnline($command->targetNick),
            };
        }

        $this->forceService->forceGuestNick($command->targetUid, null, 'ircop-rename');

        $targetHost = ($command->targetIdent ?? '') . '@' . ($command->targetHostname ?? '');

        return RenameNickResult::success(
            targetNick: $command->targetNick,
            targetUid: $command->targetUid,
            targetHost: $targetHost,
            targetIp: $command->targetIp ?? '',
            newNick: $this->guestPrefix . 'XXXXXXX',
        );
    }
}
