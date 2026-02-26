<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented by Core: send a NOTICE to a target UID.
 *
 * Services use this to reply to users without depending on Connection or IRC entities.
 */
interface SendNoticePort
{
    public function sendNotice(string $targetUid, string $message): void;
}
