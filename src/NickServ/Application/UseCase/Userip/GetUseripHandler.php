<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;

final readonly class GetUseripHandler implements GetUseripHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickHistoryService $history,
    ) {}

    public function handle(GetUserip $command): GetUseripResult
    {
        if (null === $command->ip || null === $command->hostname) {
            return GetUseripResult::notOnline($command->nickname);
        }

        $result = GetUseripResult::success(
            nickname: $command->nickname,
            ip: $command->ip,
            host: $command->hostname,
        );

        $account = $this->nickRepository->findByNick($command->nickname);
        if (null !== $account) {
            $this->history->recordWithExplicitMetadata(
                nickId: $account->getId(),
                action: 'USERIP',
                performedBy: $command->actorNickname,
                performedByNickId: null,
                message: 'history.message.userip',
                performedAt: $command->occurredAt,
            );
        }

        return $result;
    }
}
