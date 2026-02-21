<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Repository;

use App\Domain\NickServ\Entity\RegisteredNick;

interface RegisteredNickRepositoryInterface
{
    public function save(RegisteredNick $nick): void;

    public function findByNick(string $nickname): ?RegisteredNick;

    public function existsByNick(string $nickname): bool;

    /** @return RegisteredNick[] */
    public function all(): array;
}
