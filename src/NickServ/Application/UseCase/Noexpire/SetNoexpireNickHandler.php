<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;

final readonly class SetNoexpireNickHandler implements SetNoexpireNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickHistoryService $history,
    ) {}

    public function handle(SetNoexpireNick $command): SetNoexpireNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account) {
            return SetNoexpireNickResult::notRegistered($command->nickname);
        }

        if ($account->isForbidden()) {
            return SetNoexpireNickResult::forbidden($command->nickname);
        }

        if ($account->isSuspended()) {
            return SetNoexpireNickResult::suspended($command->nickname);
        }

        $account->changeNoExpire($command->noexpire);
        $this->nickRepository->save($account);

        $this->history->recordWithExplicitMetadata(
            nickId: $account->getId(),
            action: $command->noexpire ? 'NOEXPIRE_ON' : 'NOEXPIRE_OFF',
            performedBy: $command->actorNickname,
            performedByNickId: null,
            message: $command->noexpire ? 'history.message.noexpire_on' : 'history.message.noexpire_off',
            performedAt: $command->occurredAt,
        );

        return SetNoexpireNickResult::success($command->nickname, $command->noexpire);
    }
}
