<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class NetworkSynchronizationCompletedEvent
{
    public function __construct(public string $serverSid) {}
}
