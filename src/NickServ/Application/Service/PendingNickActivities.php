<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Domain\Entity\RegisteredNick;

use function array_values;

final class PendingNickActivities
{
    /** @var array<int, RegisteredNick> */
    private array $accounts = [];

    public function add(RegisteredNick $account): void
    {
        $this->accounts[$account->getId()] = $account;
    }

    /** @return list<RegisteredNick> */
    public function all(): array
    {
        return array_values($this->accounts);
    }

    public function clear(): void
    {
        $this->accounts = [];
    }
}
