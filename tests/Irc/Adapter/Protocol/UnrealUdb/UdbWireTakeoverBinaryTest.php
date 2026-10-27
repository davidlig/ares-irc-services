<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcomeKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;
use Traversable;
use UnexpectedValueException;

use function fclose;
use function fopen;
use function fwrite;
use function hash;
use function iterator_to_array;
use function rewind;
use function sprintf;
use function str_repeat;
use function str_starts_with;

/** Portable type-boundary regressions, not a claim of PostgreSQL runtime proof. */
#[CoversClass(UdbWireTakeover::class)]
final class UdbWireTakeoverBinaryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function forbidReplayRemovesCanonicalSiblingsButPreservesOtherNickAndExistingForbid(): void
    {
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $takeover = $this->takeover();
        $generation = str_repeat('a', 32);
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($takeover, $generation);
        $insert = new ReflectionMethod(UdbWireTakeover::class, 'stageInsert');
        foreach (['Alice::pass' => 'crypt:fixture', 'alice::vhost' => 'fixture.test', 'bob::swhois' => 'other'] as $path => $value) {
            $identity = UdbRecord::identity($path, 'N');
            $insert->invoke($takeover, 'udb_staged_records', ['generation_id' => $generation, 'block' => 'N', 'path' => $path, 'identity_path' => $identity, 'identity_hash' => hash('sha256', $identity, true), 'value' => $value, 'value_kind' => 1]);
        }
        $apply = new ReflectionMethod(UdbWireTakeover::class, 'applyTailMutation');
        $apply->invoke($takeover, UdbBlock::Nicks, 'INS', 'ALICE::FORBID', 'first reason', 1);
        $apply->invoke($takeover, UdbBlock::Nicks, 'INS', 'Alice::Forbid', 'updated reason', 1);
        self::assertSame(['Alice::Forbid' => 'updated reason', 'bob::swhois' => 'other'], $this->entityManager->getConnection()->fetchAllKeyValue('SELECT path, value FROM udb_staged_records ORDER BY path'));
    }

    #[Test]
    public function corruptStoredIdentityIsRejectedBeforeForbidSiblingDeletion(): void
    {
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $takeover = $this->takeover();
        $generation = str_repeat('a', 32);
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($takeover, $generation);
        $insert = new ReflectionMethod(UdbWireTakeover::class, 'stageInsert');
        // Corrupt private SQL metadata, not a claimed cryptographic collision.
        $hash = hash('sha256', 'alice::forbid', true);
        $insert->invoke($takeover, 'udb_staged_records', ['generation_id' => $generation, 'block' => 'N', 'path' => 'bob::swhois', 'identity_path' => 'bob::swhois', 'identity_hash' => $hash, 'value' => 'other', 'value_kind' => 1]);
        try {
            new ReflectionMethod(UdbWireTakeover::class, 'applyTailMutation')->invoke($takeover, UdbBlock::Nicks, 'INS', 'alice::forbid', 'fixture reason', 1);
            self::fail('Conflicting identity metadata cannot replace another nick.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('conflicting binary identity', $failure->getMessage());
        }
        self::assertSame(['bob::swhois' => 'other'], $this->entityManager->getConnection()->fetchAllKeyValue('SELECT path, value FROM udb_staged_records'));
    }

    #[Test]
    public function stagingBindsLogicalValuesAsBinaryAndKeepsAbsentTailValuesNull(): void
    {
        $types = new ReflectionMethod(UdbWireTakeover::class, 'stagingTypes')->invoke(null, ['value' => "fixture\0\xff"]);
        self::assertSame(['value' => Types::BINARY], $types);
        self::assertSame(['value' => ParameterType::NULL], new ReflectionMethod(UdbWireTakeover::class, 'stagingTypes')->invoke(null, ['value' => null]));
    }

    #[Test]
    public function stagingTypesBindBooleanAndBinaryIncludingCriteriaExplicitly(): void
    {
        $types = new ReflectionMethod(UdbWireTakeover::class, 'stagingTypes')->invoke(null, ['promoted' => false, 'complete' => true, 'identity_path' => "a\0b", 'identity_hash' => "\xff\0", 'id' => 7, 'value' => null]);
        self::assertSame(['promoted' => Types::BOOLEAN, 'complete' => Types::BOOLEAN, 'identity_path' => Types::BINARY, 'identity_hash' => Types::BINARY, 'id' => Types::INTEGER, 'value' => ParameterType::NULL], $types);
    }

    #[Test]
    public function byteaStreamDecodingPreservesZeroAndHighBytes(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        try {
            fwrite($stream, "a\0\xffb");
            rewind($stream);
            $method = new ReflectionMethod(UdbWireTakeover::class, 'sqlBinary');
            self::assertSame("a\0\xffb", $method->invoke($this->takeover(), $stream));
            self::assertSame("a\0\xffb", $method->invoke($this->takeover(), "a\0\xffb"));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function rawBooleanFormsAreDecodedRatherThanTruthinessCast(): void
    {
        $method = new ReflectionMethod(UdbWireTakeover::class, 'sqlBoolean');
        foreach ([false, 0, '0', 'f', 'false'] as $value) {
            self::assertFalse($method->invoke($this->takeover(), $value));
        }
        foreach ([true, 1, '1', 't', 'true'] as $value) {
            self::assertTrue($method->invoke($this->takeover(), $value));
        }
        $this->expectException(UnexpectedValueException::class);
        $method->invoke($this->takeover(), 'unknown');
    }

    #[Test]
    public function binaryStagingWritesEqualityAndSecondPagePreserveIdentityBytes(): void
    {
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $takeover = $this->takeover();
        $generation = str_repeat('a', 32);
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($takeover, $generation);
        $insert = new ReflectionMethod(UdbWireTakeover::class, 'stageInsert');
        $identities = [];
        for ($index = 0; $index < 257; ++$index) {
            $identity = "I\0" . sprintf('%04d', $index) . "\0clones";
            $identities[] = $identity;
            $insert->invoke($takeover, 'udb_staged_records', ['generation_id' => $generation, 'block' => 'I', 'path' => 'host' . $index . '::clones', 'identity_path' => $identity, 'identity_hash' => hash('sha256', $identity, true), 'value' => '*1', 'value_kind' => 2]);
        }
        new ReflectionMethod(UdbWireTakeover::class, 'stageUpdate')->invoke($takeover, 'udb_staged_records', ['value' => '*9'], ['generation_id' => $generation, 'identity_hash' => hash('sha256', $identities[256], true)]);
        $rows = new ReflectionMethod(UdbWireTakeover::class, 'stagedValidationRows')->invoke($takeover, UdbBlock::Ips);
        self::assertInstanceOf(Traversable::class, $rows);
        $actual = iterator_to_array($rows, false);
        self::assertCount(257, $actual);
        $paths = [];
        $lastValue = null;
        foreach ($actual as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('identityPath', $row);
            self::assertIsString($row['identityPath']);
            self::assertArrayHasKey('value', $row);
            self::assertInstanceOf(UdbValue::class, $row['value']);
            $paths[] = $row['identityPath'];
            $lastValue = $row['value'];
        }
        self::assertSame($identities, $paths);
        self::assertSame('9', $lastValue->value);
        new ReflectionMethod(UdbWireTakeover::class, 'replaceBlockFromStage')->invoke($takeover, UdbBlock::Ips);
        self::assertSame(257, $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM udb_records WHERE block = 'I'"));
    }

    #[Test]
    public function malformedSqlFieldsAndUnpreparedPrivateStagesFailClosed(): void
    {
        $takeover = $this->takeover();
        foreach ([['sqlString', null], ['sqlInt', null], ['sqlInt', '9223372036854775808'], ['sqlBinary', null]] as [$name, $value]) {
            try {
                new ReflectionMethod(UdbWireTakeover::class, $name)->invoke($takeover, $value);
                self::fail('Malformed SQL fields must be rejected.');
            } catch (UnexpectedValueException $error) {
                self::assertStringContainsString('persisted', $error->getMessage());
            }
        }
        self::assertSame(7, new ReflectionMethod(UdbWireTakeover::class, 'sqlInt')->invoke($takeover, '7'));
        foreach (['stagedDbRows', 'stagedValidationRows'] as $name) {
            $rows = new ReflectionMethod(UdbWireTakeover::class, $name)->invoke($takeover, UdbBlock::Ips);
            self::assertInstanceOf(Traversable::class, $rows);
            self::assertSame([], iterator_to_array($rows, false));
        }
        self::assertNull($takeover->roundId());
        $takeover->reset();
        $takeover->markTailReplayComplete();
        self::assertFalse($takeover->canPromote());
        foreach ([['stageReusableBlock', [UdbBlock::Ips, UdbUnsignedDecimal::fromInt(1), str_repeat('a', 64), 0]], ['applyTailMutation', [UdbBlock::Ips, 'DRP', '', null, null]], ['validatePersistedStage', [UdbBlock::Ips]], ['validateGenerationMetadata', []]] as [$name, $args]) {
            try {
                new ReflectionMethod(UdbWireTakeover::class, $name)->invokeArgs($takeover, $args);
                self::fail('An unprepared private stage cannot be operated upon.');
            } catch (LogicException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([['value' => 'bad', 'value_kind' => 255], ['value' => '*bad', 'value_kind' => 2]] as $row) {
            try {
                new ReflectionMethod(UdbWireTakeover::class, 'valueFromRow')->invoke($takeover, $row);
                self::fail('Invalid typed persisted values must fail.');
            } catch (LogicException $error) {
                self::assertStringContainsString('persisted', $error->getMessage());
            }
        }
        self::assertFalse(new ReflectionMethod(UdbWireTakeover::class, 'validTypedPath')->invoke($takeover, UdbBlock::Nicks, 'bad%ZZ', UdbValue::string('x')));
        self::assertFalse(new ReflectionMethod(UdbWireTakeover::class, 'validTypedPath')->invoke($takeover, UdbBlock::Nicks, str_repeat('a', 8193), UdbValue::string('x')));
    }

    #[Test]
    public function tailPrivateBoundaryRejectsCorruptKindsValuesAndOperations(): void
    {
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $takeover = $this->takeover();
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($takeover, str_repeat('a', 32));
        $apply = new ReflectionMethod(UdbWireTakeover::class, 'applyTailMutation');
        foreach ([['INS', '192.0.2.1::clones', '*1', 255], ['INS', '192.0.2.1::clones', '*bad', 2], ['INS', '192.0.2.1::clones', 'wrong', 1], ['BAD', '', null, null]] as [$op, $path, $value, $kind]) {
            try {
                $apply->invoke($takeover, UdbBlock::Ips, $op, $path, $value, $kind);
                self::fail('Corrupt replay input must not modify the generation.');
            } catch (LogicException $error) {
                self::assertStringContainsString('staged', $error->getMessage());
            }
        }
        $apply->invoke($takeover, UdbBlock::Ips, 'INS', '192.0.2.1', '', 0);
        $apply->invoke($takeover, UdbBlock::Ips, 'INS', '192.0.2.1::clones', '*1', 2);
        $apply->invoke($takeover, UdbBlock::Ips, 'INS', '192.0.2.1::clones', '*2', 2);
        self::assertSame('*2', $this->entityManager->getConnection()->fetchOne("SELECT value FROM udb_staged_records WHERE path = '192.0.2.1::clones'"));
        $apply->invoke($takeover, UdbBlock::Ips, 'DEL', '192.0.2.1', null, null);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_records'));
    }

    #[Test]
    public function sqlCountQuotasRejectTailAndReplayWithoutAllocatingHugeFixtures(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->method('fetchOne')->willReturnCallback(static fn (string $sql): false|int => str_starts_with($sql, 'SELECT id FROM') ? false : 500000);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $exporter = new ReflectionProperty(UdbWireTakeover::class, 'exporter')->getValue($this->takeover());
        self::assertInstanceOf(UdbRecordExporter::class, $exporter);
        $takeover = new UdbWireTakeover(new FakeUdbRecords(), new FakeBlockStates(), $em, $exporter);
        foreach (['generationId' => str_repeat('a', 32), 'rootSid' => '001', 'rootEpoch' => str_repeat('1', 16), 'tailWatermark' => UdbUnsignedDecimal::fromInt(0)] as $property => $value) {
            new ReflectionProperty(UdbWireTakeover::class, $property)->setValue($takeover, $value);
        }
        $outcome = $takeover->acceptMutation(new UdbFrame(UdbFrameKind::Del, '001', '*', epoch: str_repeat('1', 16), sequence: 1, path: 'I::192.0.2.1'));
        self::assertSame(UdbWireTakeoverOutcomeKind::Error, $outcome->kind);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('record limit');
        new ReflectionMethod(UdbWireTakeover::class, 'applyTailMutation')->invoke($takeover, UdbBlock::Ips, 'INS', '192.0.2.1::clones', '*1', 2);
    }

    private function takeover(): UdbWireTakeover
    {
        $exporter = new UdbRecordExporter($this->createStub(NickProjectionQuery::class), $this->createStub(ChannelProjectionQuery::class), $this->createStub(OperatorNetworkProjectionQuery::class), $this->createStub(GlineProjectionQuery::class), $this->createStub(ChannelLookupPort::class), $this->createStub(ActiveChannelModeSupportProviderInterface::class), new UdbChannelSuspendReasonResolver($this->createStub(TranslatorInterface::class), 'ChanServ'));

        return new UdbWireTakeover(new FakeUdbRecords(), new FakeBlockStates(), $this->entityManager, $exporter);
    }
}
