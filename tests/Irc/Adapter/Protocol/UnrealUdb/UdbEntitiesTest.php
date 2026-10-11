<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function hash;

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
        self::assertSame(UdbValueKind::String, $record->getValueKind());
        self::assertSame('NickServ!NickServ@services', $record->getUdbValue()->value);
    }

    #[Test]
    public function absentContainerAndExplicitEmptyStringRemainDifferentTypedRows(): void
    {
        $container = new UdbRecord('N', 'david', '', UdbValueKind::None);
        $emptyString = new UdbRecord('N', 'david::pass', '', UdbValueKind::String);

        self::assertSame(UdbValueKind::None, $container->getValueKind());
        self::assertSame(UdbValueKind::String, $emptyString->getValueKind());
        self::assertNotSame($container->getUdbValue()->kind, $emptyString->getUdbValue()->kind);
    }

    #[Test]
    public function recordIdentityIsCaseInsensitive(): void
    {
        self::assertSame('nick::pass', UdbRecord::identity('NICK::PASS'));
        self::assertSame('nick::pass', new UdbRecord('N', 'Nick::Pass', 'crypt:v')->getIdentityPath());
        self::assertSame('f::b64%3AQWJj::reason', new UdbRecord('K', 'F::b64%3AQWJj::REASON', 'v')->getIdentityPath());
        self::assertSame('f::b64%3AQWJj::reason', UdbRecord::identity('F::b64%3AQWJj::REASON', 'K'));
        self::assertSame('g::*@host::reason', UdbRecord::identity('G::*@HOST::REASON', 'K'));
        self::assertSame('f::b64%3aqwjj::reason', UdbRecord::identity('F::b64%3AQWJj::REASON'));
        self::assertSame(
            hash('sha256', 'f::b64%3AQWJj::reason', true),
            new UdbRecord('K', 'F::b64%3AQWJj::REASON', 'v')->getIdentityHash(),
        );
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
        $state = new UdbBlockState('L');

        self::assertSame(UdbChecksum::empty(UdbBlock::Links), $state->getChecksum());
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
    public function approvalRetainsImportedProvenanceIndependentlyOfLocalDataset(): void
    {
        $state = new UdbAuthorityState();
        $imported = new UdbDatasetCheckpoint(new UdbStreamOrigin('001', str_repeat('1', 16)), UdbUnsignedDecimal::fromInt(17));
        $state->recordImportedCheckpoint($imported);
        $state->approve(str_repeat('a', 64));
        $actual = $state->getImportedCheckpoint();
        self::assertNotNull($actual);
        self::assertSame($imported->origin->sid, $actual->origin->sid);
        self::assertSame($imported->origin->epoch, $actual->origin->epoch);
        self::assertSame((string) $imported->watermark, (string) $actual->watermark);
        self::assertNull($state->getDatasetCheckpoint());
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

    #[Test]
    public function clearingImportedProvenanceDoesNotEraseTheLocalDataset(): void
    {
        $state = new UdbAuthorityState();
        $imported = new UdbDatasetCheckpoint(new UdbStreamOrigin('001', str_repeat('1', 16)), UdbUnsignedDecimal::fromInt(9));
        $state->recordImportedCheckpoint($imported);
        $local = $state->startDatasetStream(new UdbStreamOrigin('002', str_repeat('2', 16)));
        $state->clearImportedCheckpoint();
        self::assertNull($state->getImportedCheckpoint());
        $actual = $state->getDatasetCheckpoint();
        self::assertNotNull($actual);
        self::assertSame($local->origin->sid, $actual->origin->sid);
        self::assertSame((string) $local->watermark, (string) $actual->watermark);
    }
}
