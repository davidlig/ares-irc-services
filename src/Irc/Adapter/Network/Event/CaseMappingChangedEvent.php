<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

use App\Irc\Domain\ValueObject\CaseMapping;

/** A negotiated link setting, applied before processing the remote burst. */
final readonly class CaseMappingChangedEvent
{
    public function __construct(public CaseMapping $mapping) {}
}
