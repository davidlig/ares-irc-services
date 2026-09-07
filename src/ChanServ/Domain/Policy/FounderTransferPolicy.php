<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\FounderTransferDecision;
use InvalidArgumentException;

final readonly class FounderTransferPolicy
{
    public function decide(
        int $currentFounderNickId,
        ?int $successorNickId,
        int $targetNickId,
        bool $targetRegistered,
        bool $targetSuspended,
        int $targetFoundedChannelCount,
        int $maximumChannelsPerNick,
    ): FounderTransferDecision {
        if (0 > $targetFoundedChannelCount || 0 > $maximumChannelsPerNick) {
            throw new InvalidArgumentException('Founder channel counts cannot be negative.');
        }
        if ($targetSuspended) {
            return FounderTransferDecision::TargetSuspended;
        }
        if (!$targetRegistered) {
            return FounderTransferDecision::TargetNotRegistered;
        }
        if ($targetNickId === $currentFounderNickId) {
            return FounderTransferDecision::SameFounder;
        }
        if ($targetNickId === $successorNickId) {
            return FounderTransferDecision::TargetIsSuccessor;
        }
        if ($maximumChannelsPerNick <= $targetFoundedChannelCount) {
            return FounderTransferDecision::ChannelLimitReached;
        }

        return FounderTransferDecision::Allowed;
    }
}
