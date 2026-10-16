<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\Entity\RegisteredNick;

interface RegisterNickRepository
{
    public function findByNick(string $nickname): ?RegisteredNick;

    public function findByEmail(string $email): ?RegisteredNick;

    /** Number of accounts using this email (case-insensitive). Used to enforce the per-email limit. */
    public function countByEmail(string $email): int;

    public function save(RegisteredNick $nick): void;
}
