<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

/**
 * Network capabilities required by NickServ application workflows.
 */
interface NickNetworkActions
{
    public function setUserAccount(string $targetUid, string $accountName): void;

    public function setUserVhost(string $targetUid, string $vhost, string $sourceServerSid): void;

    public function forceNick(string $targetUid, string $newNick): void;
}
