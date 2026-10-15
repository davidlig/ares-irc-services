<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Fake;

use App\CRegServ\Application\Port\Out\ChannelApprovalOutcome;
use App\CRegServ\Application\Port\Out\ChannelApprovalPort;
use DateTimeImmutable;

/**
 * Stands in for ChanServ: records what APROBAR handed over and answers a canned outcome.
 */
final class RecordingChannelApproval implements ChannelApprovalPort
{
    /** @var list<array{channelName: string, description: string, requesterAccountId: int, approverNickname: string}> */
    public array $calls = [];

    public function __construct(
        private ChannelApprovalOutcome $outcome = ChannelApprovalOutcome::Approved,
    ) {}

    public function approve(
        string $channelName,
        string $description,
        int $requesterAccountId,
        string $approverNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelApprovalOutcome {
        $this->calls[] = [
            'channelName' => $channelName,
            'description' => $description,
            'requesterAccountId' => $requesterAccountId,
            'approverNickname' => $approverNickname,
        ];

        return $this->outcome;
    }
}
