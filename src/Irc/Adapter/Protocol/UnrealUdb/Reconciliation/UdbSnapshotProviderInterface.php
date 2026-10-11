<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

/** Captures one immutable six-block view with a common persisted checkpoint. */
interface UdbSnapshotProviderInterface
{
    public function capture(): UdbCapturedSnapshot;
}
