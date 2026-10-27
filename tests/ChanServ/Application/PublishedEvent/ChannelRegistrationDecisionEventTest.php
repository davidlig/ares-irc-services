<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegistrationDecisionEvent::class)]
final class ChannelRegistrationDecisionEventTest extends TestCase
{
    public function testDecisionCarriesOnlyPostCommitFactsAcrossContexts(): void
    {
        $now = new DateTimeImmutable('2026-10-26 12:00:00');
        $until = $now->modify('+24 hours');
        $event = new ChannelRegistrationDecisionEvent(7, '#Example', 11, 22, 'Reviewer', false, 'Policy', $until, $now);

        self::assertSame(7, $event->channelId);
        self::assertSame('#Example', $event->channelName);
        self::assertSame(11, $event->requesterAccountId);
        self::assertSame(22, $event->operatorAccountId);
        self::assertSame('Reviewer', $event->operatorNickname);
        self::assertFalse($event->accepted);
        self::assertSame('Policy', $event->rejectionReason);
        self::assertSame($until, $event->restrictedUntil);
        self::assertSame($now, $event->occurredAt);
    }
}
