<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Entity;

use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ChannelRegistrationAudit::class)]
final class ChannelRegistrationAuditTest extends TestCase
{
    #[Test]
    public function storesImmutableDecisionDetailsForAcceptanceAndRejection(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $accepted = ChannelRegistrationAudit::recordDecision('#accepted', 7, 9, 'Operator', true, null, null, $at);
        self::assertSame('#accepted', $accepted->getChannelName());
        self::assertSame(7, $accepted->getRequesterAccountId());
        self::assertSame(9, $accepted->getOperatorAccountId());
        self::assertSame('Operator', $accepted->getOperatorNickname());
        self::assertTrue($accepted->wasAccepted());
        self::assertNull($accepted->getRejectionReason());
        self::assertNull($accepted->getRestrictedUntil());
        self::assertSame($at, $accepted->getOccurredAt());

        $until = $at->modify('+24 hours');
        $rejected = ChannelRegistrationAudit::recordDecision('#rejected', 8, 9, 'Operator', false, 'Reason', $until, $at);
        self::assertFalse($rejected->wasAccepted());
        self::assertSame('Reason', $rejected->getRejectionReason());
        self::assertSame($until, $rejected->getRestrictedUntil());
        new ReflectionProperty(ChannelRegistrationAudit::class, 'id')->setValue($rejected, 37);
        self::assertSame(37, $rejected->getId());
        self::assertSame($at->format(DATE_ATOM), $rejected->getOccurredAt()->format(DATE_ATOM));
    }
}
