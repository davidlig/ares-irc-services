<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

use function count;

class InMemoryNetworkUserRepository implements NetworkUserRepositoryInterface
{
    /** @var array<string, NetworkUser> keyed by UID string */
    private array $users = [];

    public function add(NetworkUser $user): void
    {
        $this->users[$user->uid->value] = $user;
    }

    public function removeByUid(Uid $uid): void
    {
        unset($this->users[$uid->value]);
    }

    public function findByUid(Uid $uid): ?NetworkUser
    {
        return $this->users[$uid->value] ?? null;
    }

    public function findByNick(Nick $nick): ?NetworkUser
    {
        foreach ($this->users as $user) {
            if ($user->getNick()->equals($nick)) {
                return $user;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function count(): int
    {
        return count($this->users);
    }
}
