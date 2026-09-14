<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;

final readonly class NickProjectionResolver implements NickProjectionQuery
{
    public function __construct(private RegisteredNickRepositoryInterface $nicks) {}

    public function all(): array
    {
        return array_values(array_map(self::project(...), $this->nicks->all()));
    }

    public function findById(int $id): ?NickProjection
    {
        $nick = $this->nicks->findById($id);

        return null !== $nick ? self::project($nick) : null;
    }

    private static function project(RegisteredNick $nick): NickProjection
    {
        $forbidden = $nick->isForbidden();

        return new NickProjection(
            $nick->getId(),
            $nick->getNickname(),
            $nick->getPasswordHash(),
            $nick->getVhost(),
            $forbidden,
            $forbidden ? $nick->getReason() : null,
        );
    }
}
