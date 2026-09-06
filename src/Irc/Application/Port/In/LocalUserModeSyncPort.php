<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

use App\Irc\Domain\ValueObject\Uid;

/**
 * Applies a user mode delta to the local view of the network state.
 *
 * Used when services originate a mode change (e.g. set +r for identified)
 * so that the local NetworkUser state stays in sync even if the IRCd does
 * not echo the change back to the services connection.
 */
interface LocalUserModeSyncPort
{
    /**
     * Applies the given mode delta (e.g. "+r" or "-r") to the local state
     * for the user identified by $uid.
     */
    public function apply(Uid $uid, string $modeDelta): void;
}
