<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Restore;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;

final readonly class RestoreNickHandler implements RestoreNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickDropService $dropService,
    ) {}

    public function handle(RestoreNick $command): RestoreNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account) {
            return RestoreNickResult::notRegistered($command->nickname);
        }

        if (!$account->isPendingDeletion()) {
            return RestoreNickResult::notPendingDeletion($command->nickname);
        }

        $this->dropService->restoreNick($account, $command->operatorNick);

        return RestoreNickResult::success($command->nickname);
    }
}
