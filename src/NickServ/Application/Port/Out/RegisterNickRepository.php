<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\Domain\NickServ\Entity\RegisteredNick;

interface RegisterNickRepository
{
    public function findByNick(string $nickname): ?RegisteredNick;

    public function findByEmail(string $email): ?RegisteredNick;

    public function save(RegisteredNick $nick): void;
}
