<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Event\NickEmailChangedEvent;
use App\NickServ\Application\Event\NickPasswordChangedEvent;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;

interface NickServEventPublisher
{
    public function publish(
        NickDropCleanupEvent|NickDropEvent|NickEmailChangedEvent|NickIdentifiedEvent|NickPasswordChangedEvent|NickPasswordHashAvailable|NickSuspendedEvent|NickUnsuspendedEvent|NickVhostChangedEvent|UserDeidentifiedEvent $event,
    ): void;
}
