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

    /** @var array<string, string> lowercase nick => UID (for O(1) findByNick) */
    private array $byNick = [];

    public function add(NetworkUser $user): void
    {
        $this->users[$user->uid->value] = $user;
        $this->byNick[strtolower($user->getNick()->value)] = $user->uid->value;
    }

    public function removeByUid(Uid $uid): void
    {
        $user = $this->users[$uid->value] ?? null;
        if (null !== $user) {
            unset($this->byNick[strtolower($user->getNick()->value)]);
        }
        unset($this->users[$uid->value]);
    }

    public function updateNick(Uid $uid, Nick $oldNick, Nick $newNick): void
    {
        unset($this->byNick[strtolower($oldNick->value)]);
        $this->byNick[strtolower($newNick->value)] = $uid->value;
    }

    public function findByUid(Uid $uid): ?NetworkUser
    {
        return $this->users[$uid->value] ?? null;
    }

    public function findByNick(Nick $nick): ?NetworkUser
    {
        $uid = $this->byNick[strtolower($nick->value)] ?? null;
        if (null === $uid) {
            return null;
        }

        return $this->users[$uid] ?? null;
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
