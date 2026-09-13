<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use DateTimeImmutable;
use InvalidArgumentException;
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
        self::assertSame('f::b64:QWJj::reason', new UdbRecord('K', 'F::b64:QWJj::REASON', 'v')->getIdentityPath());
        self::assertSame('f::b64:QWJj::reason', UdbRecord::identity('F::b64:QWJj::REASON', 'K'));
        self::assertSame('g::*@host::reason', UdbRecord::identity('G::*@HOST::REASON', 'K'));
        self::assertSame('f::b64:qwjj::reason', UdbRecord::identity('F::b64:QWJj::REASON'));
    }

    #[Test]
    public function recordValueUpdateChangesValueAndTimestamp(): void
    {
        $record = new UdbRecord('I', '1.2.3.4::clones', '*0005');
        $before = $record->getUpdatedAt();

        self::assertSame('*5', $record->getValue());
        $record->updateValue('*00010');

        self::assertSame('*10', $record->getValue());
        self::assertSame($record->getIdentityPath(), '1.2.3.4::clones');
        self::assertGreaterThanOrEqual($before->getTimestamp(), $record->getUpdatedAt()->getTimestamp());
    }

    #[Test]
    public function blockStateHoldsManifestAndModificationTime(): void
    {
        $modifiedAt = new DateTimeImmutable('2026-08-30 12:00:00');
        $digest = str_repeat('a', 64);
        $state = new UdbBlockState('S', $digest, $modifiedAt, 17);
        new ReflectionClass(UdbBlockState::class)->getProperty('id')->setValue($state, 3);

        self::assertSame(3, $state->getId());
        self::assertSame('S', $state->getBlock());
        self::assertSame($digest, $state->getChecksum());
        self::assertSame($digest, $state->getDigest());
        self::assertSame(17, $state->getRecordCount());
        self::assertSame($modifiedAt, $state->getModifiedAt());
    }

    #[Test]
    public function blockStateDefaultsSyncTimeToNow(): void
    {
        $before = new DateTimeImmutable();
        $state = new UdbBlockState('L', str_repeat('0', 64));

        self::assertSame(str_repeat('0', 64), $state->getChecksum());
        self::assertSame(0, $state->getRecordCount());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $state->getModifiedAt()->getTimestamp());
    }

    #[Test]
    public function blockStateUpdateRefreshesTheCompleteManifest(): void
    {
        $state = new UdbBlockState('L', str_repeat('0', 64), new DateTimeImmutable('2026-08-30 12:00:00'));
        $modifiedAt = new DateTimeImmutable('2026-09-13 16:00:00');

        $state->update(str_repeat('b', 64), 2, $modifiedAt);

        self::assertSame(str_repeat('b', 64), $state->getChecksum());
        self::assertSame(2, $state->getRecordCount());
        self::assertSame($modifiedAt, $state->getModifiedAt());
    }

    #[Test]
    public function blockStateRejectsNonCanonicalDigests(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UdbBlockState('S', str_repeat('A', 64));
    }

    #[Test]
    public function blockStateRejectsNegativeRecordCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UdbBlockState('S', str_repeat('a', 64), recordCount: -1);
    }

    #[Test]
    public function authorityStateRequiresExplicitApprovalAndCanBeRevoked(): void
    {
        $state = new UdbAuthorityState();

        self::assertFalse($state->isApproved());
        self::assertNull($state->getApprovedAt());
        self::assertNull($state->getFingerprint());
        self::assertNull($state->getProtocolRevision());

        $state->approve(str_repeat('a', 64));

        self::assertTrue($state->isApproved());
        self::assertNotNull($state->getApprovedAt());
        self::assertSame(str_repeat('a', 64), $state->getFingerprint());
        self::assertSame(UdbAuthorityState::CURRENT_PROTOCOL_REVISION, $state->getProtocolRevision());

        $state->revoke();

        self::assertFalse($state->isApproved());
        self::assertNull($state->getApprovedAt());
        self::assertNull($state->getFingerprint());
        self::assertNull($state->getProtocolRevision());
    }

    #[Test]
    public function authorityApprovalIsInvalidWhenItsPersistedRevisionIsStale(): void
    {
        $state = new UdbAuthorityState();
        $state->approve(str_repeat('a', 64));
        new ReflectionClass(UdbAuthorityState::class)->getProperty('protocolRevision')->setValue($state, str_repeat('b', 40));

        self::assertFalse($state->isApproved());
        self::assertSame(str_repeat('b', 40), $state->getProtocolRevision());
    }
}
