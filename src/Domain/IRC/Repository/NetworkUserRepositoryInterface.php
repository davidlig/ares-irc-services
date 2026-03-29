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

    /**
     * Updates the nick index when a user changes nick (keeps findByNick O(1)).
     */
    public function updateNick(Uid $uid, Nick $oldNick, Nick $newNick): void;

    public function findByUid(Uid $uid): ?NetworkUser;

    public function findByNick(Nick $nick): ?NetworkUser;

    /**
     * @return NetworkUser[]
     */
    public function all(): array;

    public function count(): int;

    /**
     * Updates the virtual host for a user (called after sending CHGHOST).
     */
    public function updateVirtualHost(Uid $uid, string $vhost): void;
}
