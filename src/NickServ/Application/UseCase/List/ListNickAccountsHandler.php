<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\List;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

final readonly class ListNickAccountsHandler implements ListNickAccountsHandlerInterface
{
    private int $pageSize;

    public function __construct(
        private RegisteredNickRepositoryInterface $repository,
        int $pageSize,
    ) {
        $this->pageSize = max(1, $pageSize);
    }

    public function handle(ListNickAccounts $query): ListNickAccountsResult
    {
        $page = max(1, $query->page);
        $total = $this->repository->countByPattern($query->pattern);
        $lastPage = (int) ceil($total / $this->pageSize);
        $entries = [];

        if ($page <= $lastPage) {
            foreach ($this->repository->searchByPattern(
                $query->pattern,
                ($page - 1) * $this->pageSize,
                $this->pageSize,
            ) as $nick) {
                $entries[] = new ListedNickAccount(
                    $nick->getNickname(),
                    $nick->getRegisteredAt(),
                    $nick->getLastSeenAt(),
                    $nick->getLastConnectIp(),
                    $nick->getStatus(),
                );
            }
        }

        return new ListNickAccountsResult($query->pattern, $page, $this->pageSize, $total, $entries);
    }
}
