<?php

declare(strict_types=1);

namespace App\Domain\IRC\Repository;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

interface NetworkUserRepositoryInterface
{
    public function add(NetworkUser $user): void;

    public function removeByUid(Uid $uid): void;

    public function findByUid(Uid $uid): ?NetworkUser;

    public function findByNick(Nick $nick): ?NetworkUser;

    /**
     * @return NetworkUser[]
     */
    public function all(): array;

    public function count(): int;
}
