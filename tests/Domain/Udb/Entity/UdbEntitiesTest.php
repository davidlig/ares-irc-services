<?php

declare(strict_types=1);

namespace App\Tests\Domain\Udb\Entity;

use App\Domain\Udb\Entity\UdbAuthorityState;
use App\Domain\Udb\Entity\UdbBlockState;
use App\Domain\Udb\Entity\UdbRecord;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UdbRecord::class)]
#[CoversClass(UdbBlockState::class)]
#[CoversClass(UdbAuthorityState::class)]
final class UdbEntitiesTest extends TestCase
{
    #[Test]
    public function recordHoldsBlockPathAndValue(): void
    {
        $record = new UdbRecord('S', 'nickserv', 'NickServ!NickServ@services');
        new ReflectionClass(UdbRecord::class)->getProperty('id')->setValue($record, 9);

        self::assertSame(9, $record->getId());
        self::assertSame('S', $record->getBlock());
        self::assertSame('nickserv', $record->getPath());
        self::assertSame('nickserv', $record->getIdentityPath());
        self::assertSame('NickServ!NickServ@services', $record->getValue());
    }

    #[Test]
    public function recordIdentityIsCaseInsensitive(): void
    {
        self::assertSame('nick::pass', UdbRecord::identity('NICK::PASS'));
        self::assertSame('nick::pass', new UdbRecord('N', 'Nick::Pass', 'v')->getIdentityPath());
    }

    #[Test]
    public function recordValueUpdateChangesValueAndTimestamp(): void
    {
        $record = new UdbRecord('I', '1.2.3.4::clones', '*5');
        $before = $record->getUpdatedAt();

        $record->updateValue('*10');

        self::assertSame('*10', $record->getValue());
        self::assertSame($record->getIdentityPath(), '1.2.3.4::clones');
        self::assertGreaterThanOrEqual($before->getTimestamp(), $record->getUpdatedAt()->getTimestamp());
    }

    #[Test]
    public function blockStateHoldsChecksumAndSyncTime(): void
    {
        $syncedAt = new DateTimeImmutable('2026-08-30 12:00:00');
        $state = new UdbBlockState('S', 'AAAA1111', $syncedAt);
        new ReflectionClass(UdbBlockState::class)->getProperty('id')->setValue($state, 3);

        self::assertSame(3, $state->getId());
        self::assertSame('S', $state->getBlock());
        self::assertSame('AAAA1111', $state->getChecksum());
        self::assertSame($syncedAt, $state->getSyncedAt());
    }

    #[Test]
    public function blockStateDefaultsSyncTimeToNow(): void
    {
        $before = new DateTimeImmutable();
        $state = new UdbBlockState('L', '00000000');

        self::assertSame('00000000', $state->getChecksum());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $state->getSyncedAt()->getTimestamp());
    }

    #[Test]
    public function blockStateUpdateRefreshesChecksumAndTime(): void
    {
        $state = new UdbBlockState('L', '00000000', new DateTimeImmutable('2026-08-30 12:00:00'));

        $state->update('BBBB2222');

        self::assertSame('BBBB2222', $state->getChecksum());
        self::assertGreaterThan(new DateTimeImmutable('2026-08-30 12:00:00')->getTimestamp(), $state->getSyncedAt()->getTimestamp());
    }

    #[Test]
    public function authorityStateRequiresExplicitApprovalAndCanBeRevoked(): void
    {
        $state = new UdbAuthorityState();

        self::assertFalse($state->isApproved());
        self::assertNull($state->getApprovedAt());
        self::assertNull($state->getFingerprint());

        $state->approve(str_repeat('a', 64));

        self::assertTrue($state->isApproved());
        self::assertNotNull($state->getApprovedAt());
        self::assertSame(str_repeat('a', 64), $state->getFingerprint());

        $state->revoke();

        self::assertFalse($state->isApproved());
        self::assertNull($state->getApprovedAt());
        self::assertNull($state->getFingerprint());
    }
}
