<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Domain\IRC\Network\NetworkUser;

/**
 * Holds burst state: whether the initial network burst is complete and users
 * that joined during the burst (processed when burst ends).
 * Injected into NickProtectionService so the subscriber can remain stateless.
 */
class BurstState
{
    private bool $complete = false;

    /** @var NetworkUser[] Users received during the burst, processed after EOS. */
    private array $pendingUsers = [];

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function markComplete(): void
    {
        $this->complete = true;
    }

    public function addPending(NetworkUser $user): void
    {
        $this->pendingUsers[] = $user;
    }

    /**
     * Returns and clears the pending users list.
     *
     * @return NetworkUser[]
     */
    public function takePending(): array
    {
        $pending = $this->pendingUsers;
        $this->pendingUsers = [];

        return $pending;
    }
}
