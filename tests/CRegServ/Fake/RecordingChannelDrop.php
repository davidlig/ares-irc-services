<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Fake;

use App\CRegServ\Application\Port\Out\ChannelDropOutcome;
use App\CRegServ\Application\Port\Out\ChannelDropPort;
use DateTimeImmutable;

/**
 * Stands in for ChanServ: records what DROP handed over and answers a canned outcome.
 */
final class RecordingChannelDrop implements ChannelDropPort
{
    /** @var list<array{channelName: string, actorNickname: string}> */
    public array $calls = [];

    public function __construct(
        private ChannelDropOutcome $outcome = ChannelDropOutcome::Dropped,
    ) {}

    public function drop(
        string $channelName,
        string $actorNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelDropOutcome {
        $this->calls[] = [
            'channelName' => $channelName,
            'actorNickname' => $actorNickname,
        ];

        return $this->outcome;
    }
}
