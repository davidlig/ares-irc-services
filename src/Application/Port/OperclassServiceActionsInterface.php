<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Optional protocol capability: assignment of IRCd operclasses to operators.
 *
 * Implemented only by protocol service actions that can apply operclasses
 * natively (UnrealIRCd SVSO, UnrealUdb N::oper). Consumers must feature-
 * detect via instanceof — this port is never part of the mandatory
 * ProtocolServiceActionsInterface, so protocol implementations stay agnostic.
 */
interface OperclassServiceActionsInterface
{
    /** Assign an operclass to an online user, or remove IRC operator status when null. */
    public function setUserOperclass(string $serverSid, string $targetUid, string $targetNickname, ?string $operclass): void;
}
