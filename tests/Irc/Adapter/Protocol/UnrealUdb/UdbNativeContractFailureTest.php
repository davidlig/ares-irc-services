<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSnapshotValidator;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbLogicalDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbNativeRowCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueEncoder;
use Closure;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

#[CoversClass(UdbAuthorityState::class)]
#[CoversClass(UdbBlockState::class)]
#[CoversClass(UdbDatasetCheckpoint::class)]
#[CoversClass(UdbRecord::class)]
#[CoversClass(UdbSchema::class)]
#[CoversClass(UdbSnapshotValidator::class)]
#[CoversClass(UdbStreamOrigin::class)]
#[CoversClass(UdbCapturedSnapshot::class)]
#[CoversClass(UdbMutation::class)]
#[CoversClass(UdbLogicalDigest::class)]
#[CoversClass(UdbNativeRowCodec::class)]
#[CoversClass(UdbValueEncoder::class)]
final class UdbNativeContractFailureTest extends TestCase
{
    #[Test]
    public function checkpointsPreserveImportedProvenanceAndAdvanceOnlyTheirActiveOrigin(): void
    {
        $state = new UdbAuthorityState();
        $local = new UdbStreamOrigin('002', '0123456789abcdef');
        $remote = new UdbStreamOrigin('001', '1111111111111111');
        self::assertNull($state->getDatasetCheckpoint());
        self::assertNull($state->getImportedCheckpoint());
        $imported = new UdbDatasetCheckpoint($remote, UdbUnsignedDecimal::fromInt(8));
        $state->recordImportedCheckpoint($imported);
        $checkpoint = $state->getImportedCheckpoint();
        self::assertNotNull($checkpoint);
        self::assertSame('001', $checkpoint->origin->sid);
        self::assertSame('1111111111111111', $checkpoint->origin->epoch);
        self::assertSame('8', (string) $checkpoint->watermark);
        self::assertSame('0', (string) $state->startDatasetStream($local)->watermark);
        self::assertSame('1', (string) $state->advanceDatasetCheckpoint($local)->watermark);
        self::assertSame('1', (string) $state->startDatasetStream($local)->watermark);
        $checkpoint = $state->getImportedCheckpoint();
        self::assertNotNull($checkpoint);
        self::assertSame('001', $checkpoint->origin->sid);
        self::assertSame('1111111111111111', $checkpoint->origin->epoch);
        self::assertSame('8', (string) $checkpoint->watermark);
        self::assertSame('0', (string) $state->startDatasetStream($remote)->watermark);
    }

    #[Test]
    public function foreignOriginCannotConsumeDatasetSequence(): void
    {
        $state = new UdbAuthorityState();
        $state->startDatasetStream(new UdbStreamOrigin('002', '0123456789abcdef'));
        $this->expectException(UnexpectedValueException::class);
        $state->advanceDatasetCheckpoint(new UdbStreamOrigin('001', '1111111111111111'));
    }

    #[Test]
    public function exhaustedDatasetCannotWrapItsSequence(): void
    {
        $state = new UdbAuthorityState();
        $origin = new UdbStreamOrigin('002', '0123456789abcdef');
        $state->recordDatasetCheckpoint(new UdbDatasetCheckpoint($origin, UdbUnsignedDecimal::parse(UdbUnsignedDecimal::MAX) ?? throw new RuntimeException('Invalid fixture.')));
        $this->expectException(OverflowException::class);
        $state->advanceDatasetCheckpoint($origin);
    }

    #[Test]
    public function invalidPersistedWatermarksNeverBecomeCheckpoints(): void
    {
        $state = new UdbAuthorityState();
        $origin = new UdbStreamOrigin('002', '0123456789abcdef');
        $checkpoint = new UdbDatasetCheckpoint($origin, UdbUnsignedDecimal::fromInt(1));
        $state->recordDatasetCheckpoint($checkpoint);
        $state->recordImportedCheckpoint($checkpoint);
        new ReflectionProperty(UdbAuthorityState::class, 'datasetWatermark')->setValue($state, 'invalid');
        new ReflectionProperty(UdbAuthorityState::class, 'originWatermark')->setValue($state, 'invalid');
        self::assertNull($state->getDatasetCheckpoint());
        self::assertNull($state->getImportedCheckpoint());
    }

    /** @return iterable<string, array{Closure(): mixed, class-string<Throwable>}> */
    public static function invalidContracts(): iterable
    {
        yield 'invalid SID' => [static fn () => new UdbStreamOrigin('bad', '0123456789abcdef'), InvalidArgumentException::class];
        yield 'invalid epoch' => [static fn () => new UdbStreamOrigin('002', 'ABCDEF0123456789'), InvalidArgumentException::class];
        yield 'invalid block state' => [static fn () => new UdbBlockState('?', str_repeat('a', 64), recordCount: 0), InvalidArgumentException::class];
        yield 'unknown record field' => [static fn () => new UdbRecord('S', 'unknown', 'value'), InvalidArgumentException::class];
        yield 'nonempty NONE' => [static fn () => new UdbRecord('N', 'alice', 'value', UdbValueKind::None), InvalidArgumentException::class];
        yield 'unmarked number' => [static fn () => new UdbRecord('I', '1.2.3.4::clones', '5', UdbValueKind::Number), InvalidArgumentException::class];
        yield 'numeric overflow' => [static fn () => UdbNativeRowCodec::decimalToLittleEndian('18446744073709551616'), InvalidArgumentException::class];
        yield 'oversized string' => [static fn () => UdbValueEncoder::encode(UdbValue::string(str_repeat('v', 4097))), InvalidArgumentException::class];
        yield 'untyped logical map' => [static fn () => UdbLogicalDigest::fromMap(UdbBlock::Settings, ['clones' => '*5']), InvalidArgumentException::class];
    }

    /** @param class-string<Throwable> $exception */
    #[Test]
    #[DataProvider('invalidContracts')]
    public function malformedContractInputsAreRejected(Closure $operation, string $exception): void
    {
        $this->expectException($exception);
        $operation();
    }

    #[Test]
    public function invalidRecordUpdateLeavesThePriorValueIntact(): void
    {
        $record = new UdbRecord('I', '1.2.3.4::clones', '*5');
        try {
            $record->updateValue('bad');
            self::fail('Invalid number accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame('*5', $record->getValue());
        }
    }

    #[Test]
    public function typedContainersAndScalarKindsRemainDistinct(): void
    {
        self::assertNull(UdbSchema::typedValueFor(UdbBlock::Nicks, [], ''));
        self::assertTrue(UdbSchema::validateTyped(UdbBlock::Nicks, ['alice'], UdbValue::none()));
        self::assertFalse(UdbSchema::validateTyped(UdbBlock::Nicks, ['alice'], UdbValue::string('')));
        self::assertTrue(UdbSchema::validateTyped(UdbBlock::Channels, ['#chan', 'access', 'alice'], UdbValue::string('member')));
        self::assertTrue(UdbSchema::validateTyped(UdbBlock::Channels, ['#chan', 'access', 'alice'], UdbValue::number(UdbUnsignedDecimal::fromInt(5))));
        self::assertFalse(UdbSchema::validateTyped(UdbBlock::Settings, ['clones'], UdbValue::string('*5')));
        self::assertNull(UdbSchema::typedValueForStoredRecord('?', 'clones', '*5'));
        self::assertNull(UdbSchema::typedValueForStoredRecord('N', 'bad%00::vhost', 'host.test'));
        self::assertFalse(UdbSchema::validateAggregateTyped(UdbBlock::Nicks, ['alice::forbid' => UdbValue::string('reason'), 'alice::vhost' => UdbValue::string('host.test')]));
        self::assertFalse(UdbSchema::validateAggregateTyped(UdbBlock::Nicks, ['bad%00::vhost' => UdbValue::string('host.test')]));
        self::assertTrue(UdbMutation::dropBlock(UdbBlock::Ips)->blockDrop);
    }

    #[Test]
    public function orderedValidationSeparatesAggregatesAndRejectsMalformedRows(): void
    {
        $validator = new UdbSnapshotValidator();
        self::assertTrue($validator->validate(UdbBlock::Nicks, [
            ['path' => 'alice::vhost', 'value' => UdbValue::string('a.test'), 'identityPath' => 'alice::vhost'],
            ['path' => 'bob::vhost', 'value' => UdbValue::string('b.test'), 'identityPath' => 'bob::vhost'],
        ]));
        self::assertFalse($validator->validate(UdbBlock::Nicks, [
            ['path' => 'alice::forbid', 'value' => UdbValue::string('reason'), 'identityPath' => 'alice::forbid'],
            ['path' => 'alice::vhost', 'value' => UdbValue::string('a.test'), 'identityPath' => 'alice::vhost'],
            ['path' => 'bob::vhost', 'value' => UdbValue::string('b.test'), 'identityPath' => 'bob::vhost'],
        ]));
        self::assertFalse($validator->validate(UdbBlock::Nicks, [['path' => 'bad%00::vhost', 'value' => UdbValue::string('host.test'), 'identityPath' => 'bad']]));
        self::assertFalse($validator->validate(UdbBlock::Ips, [['path' => '1.2.3.4::clones', 'value' => UdbValue::string('not-number'), 'identityPath' => '1.2.3.4::clones']]));
    }

    #[Test]
    public function capturedSnapshotRejectsAnUnknownPersistedKind(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'udb-spool-');
        self::assertIsString($file);
        file_put_contents($file, pack('NCN', 0, 255, 0));
        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array(UdbCapturedSnapshot::readFile($file));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function truncatedSpoolHeaderIsRejectedWithoutLeakingItsFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'udb-spool-');
        self::assertIsString($file);
        file_put_contents($file, 'bad');
        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array(UdbCapturedSnapshot::readFile($file));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function completedInvalidProfileFailsWhenTheNextAggregateBegins(): void
    {
        $rows = [];
        foreach (['action' => 'block', 'match-type' => 'regex', 'reason' => 'fixture', 'targets' => 'p'] as $key => $value) {
            $rows[] = ['path' => 'F::b64%3AWw==::' . $key, 'identityPath' => $key, 'value' => UdbValue::string($value)];
        }
        $rows[] = ['path' => 'G::*@fixture.test::reason', 'identityPath' => 'g', 'value' => UdbValue::string('fixture')];
        self::assertFalse(new UdbSnapshotValidator()->validate(UdbBlock::Lines, $rows));
    }

    #[Test]
    public function profileGroupingValidatesEachCompletedNickBeforeResetting(): void
    {
        $rows = [
            ['path' => 'alice::forbid', 'value' => UdbValue::string('fixture'), 'identityPath' => 'alice::forbid'],
            ['path' => 'alice::vhost', 'value' => UdbValue::string('fixture.test'), 'identityPath' => 'alice::vhost'],
            ['path' => 'bob::vhost', 'value' => UdbValue::string('fixture.test'), 'identityPath' => 'bob::vhost'],
        ];
        self::assertFalse(new UdbSnapshotValidator()->validate(UdbBlock::Nicks, $rows));
    }

    #[Test]
    public function nonProfileBlocksValidateRowsWithoutAggregateGrouping(): void
    {
        self::assertTrue(new UdbSnapshotValidator()->validate(UdbBlock::Ips, [['path' => '192.0.2.1::clones', 'value' => UdbValue::number(UdbUnsignedDecimal::fromInt(1)), 'identityPath' => '192.0.2.1::clones']]));
    }
}
