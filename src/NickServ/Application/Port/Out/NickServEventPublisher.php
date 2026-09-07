<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;

interface NickServEventPublisher
{
    public function publish(
        NickDropCleanupEvent|NickDropEvent|NickIdentifiedEvent|UserDeidentifiedEvent $event,
    ): void;
}
