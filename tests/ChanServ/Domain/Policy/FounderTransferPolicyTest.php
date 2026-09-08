<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\FounderTransferPolicy;
use App\ChanServ\Domain\ValueObject\FounderTransferDecision;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FounderTransferPolicy::class)]
#[CoversClass(FounderTransferDecision::class)]
final class FounderTransferPolicyTest extends TestCase
{
    #[Test]
    public function transferRequiresAnEligibleDifferentNonSuccessorBelowTheChannelLimit(): void
    {
        $policy = new FounderTransferPolicy();

        self::assertSame(FounderTransferDecision::TargetSuspended, $policy->decide(1, 2, 3, false, true, 0, 3));
        self::assertSame(FounderTransferDecision::TargetNotRegistered, $policy->decide(1, 2, 3, false, false, 0, 3));
        self::assertSame(FounderTransferDecision::SameFounder, $policy->decide(1, 2, 1, true, false, 0, 3));
        self::assertSame(FounderTransferDecision::TargetIsSuccessor, $policy->decide(1, 2, 2, true, false, 0, 3));
        self::assertSame(FounderTransferDecision::ChannelLimitReached, $policy->decide(1, null, 3, true, false, 3, 3));
        self::assertSame(FounderTransferDecision::Allowed, $policy->decide(1, null, 3, true, false, 2, 3));
    }

    #[Test]
    public function transferRejectsNegativeLimitsOrCounts(): void
    {
        $policy = new FounderTransferPolicy();

        try {
            $policy->decide(1, null, 3, true, false, -1, 3);
            self::fail('Expected a negative founded-channel count to fail.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $policy->decide(1, null, 3, true, false, 0, -1);
    }

    #[Test]
    public function channelLimitDecisionRejectsNegativeCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FounderTransferPolicy()->decideChannelLimit(-1, 3);
    }
}
