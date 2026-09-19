<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Public network capability for setting a user's visible host. */
interface UserVhostSetter
{
    public function setUserVhost(string $targetUid, string $vhost, string $sourceServerSid): void;
}
