<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Repository;

use App\Domain\OperServ\Entity\Motd;

interface MotdRepositoryInterface
{
    public function save(Motd $motd): void;

    public function remove(Motd $motd): void;

    public function findById(int $id): ?Motd;

    /** @return Motd[] */
    public function findAll(): array;

    /** @return Motd[] */
    public function findActive(): array;

    public function countActive(): int;

    /** @return Motd[] */
    public function findExpired(): array;

    public function deleteByNickId(int $nickId): void;
}
