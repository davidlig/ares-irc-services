<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Whoip;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

final readonly class FindNicknamesByLastConnectIpHandler implements FindNicknamesByLastConnectIpHandlerInterface
{
    public function __construct(private RegisteredNickRepositoryInterface $repository) {}

    /** @return list<string> */
    public function handle(FindNicknamesByLastConnectIp $query): array
    {
        return $this->repository->findNicknamesByLastConnectIp($query->ip);
    }
}
