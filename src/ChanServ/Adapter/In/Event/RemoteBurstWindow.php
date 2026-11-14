<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

/**
 * Whether the remote server's burst is still arriving on the current link.
 *
 * Opened when the link is established and closed when it is lost or as soon as network
 * synchronization completes, before any other end-of-sync listener runs, so a failing
 * end-of-burst listener cannot keep it open.
 */
final class RemoteBurstWindow
{
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
