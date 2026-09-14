<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbOfflineTakeover;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function file_put_contents;
use function flock;
use function fopen;
use function mkdir;
use function rmdir;
use function strlen;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;

#[CoversClass(UdbOfflineTakeover::class)]
#[Group('integration')]
final class UdbOfflineTakeoverTest extends DoctrineIntegrationTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/ares-udb-takeover-' . uniqid('', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (['.udb_state', '.udb.lock', 'udb_N.db', 'udb_C.db', 'udb_I.db', 'udb_S.db', 'udb_L.db', 'udb_K.db'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function replacesTheStoreAtomicallyAndRetainsTheSnapshotPropagator(): void
    {
        $this->entityManager->persist(new UdbRecord('N', 'stale::vhost', 'stale.example'));
        $this->entityManager->flush();

        $this->writeValidGeneration();
        $fingerprint = $this->takeover()->takeover($this->directory);

        self::assertSame(64, strlen($fingerprint));
        self::assertSame([], $this->records('N'));
        self::assertSame(['1.2.3.4::clones' => '*5'], $this->records('I'));
        self::assertSame(['propagator' => 'hub1.example, hub2.example'], $this->records('S'));
        self::assertSame(['hub1.example::options' => '*1'], $this->records('L'));
        $blockStates = $this->entityManager->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s')->getResult();
        self::assertIsArray($blockStates);
        self::assertCount(6, $blockStates);

        $authority = $this->entityManager->find(UdbAuthorityState::class, 1);
        self::assertInstanceOf(UdbAuthorityState::class, $authority);
        self::assertTrue($authority->isApproved());
        self::assertSame($fingerprint, $authority->getFingerprint());
    }

    #[Test]
    public function invalidSnapshotsDoNotReplaceOrApproveTheStore(): void
    {
        $this->entityManager->persist(new UdbRecord('N', 'kept::vhost', 'kept.example'));
        $this->entityManager->flush();
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/udb_S.db', "; UDB Block S - Version 1\n; Generation: 2\n");

        $this->expectException(RuntimeException::class);
        try {
            $this->takeover()->takeover($this->directory);
        } finally {
            self::assertSame(['kept::vhost' => 'kept.example'], $this->records('N'));
            self::assertNull($this->entityManager->find(UdbAuthorityState::class, 1));
        }
    }

    #[Test]
    public function dryRunDoesNotReplaceTheStoreOrApproveAuthority(): void
    {
        $this->entityManager->persist(new UdbRecord('N', 'kept::vhost', 'kept.example'));
        $this->entityManager->flush();
        $this->writeValidGeneration();

        self::assertSame(64, strlen($this->takeover()->takeover($this->directory, false)));
        self::assertSame(['kept::vhost' => 'kept.example'], $this->records('N'));
        self::assertNull($this->entityManager->find(UdbAuthorityState::class, 1));
    }

    #[Test]
    public function acceptsAnOfflineGenerationAtUnsignedLongMaxWithoutCastingIt(): void
    {
        $this->writeValidGeneration('18446744073709551615');

        self::assertSame(64, strlen($this->takeover()->takeover($this->directory, false)));
    }

    #[Test]
    public function lastSyncUsesBoundedTimeTSemantics(): void
    {
        foreach (['0', '1700000000', '9223372036854775807', '0001'] as $valid) {
            $this->writeValidGenerationWithLastSync($valid);
            self::assertSame(64, strlen($this->takeover()->takeover($this->directory, false)));
        }
    }

    #[Test]
    public function lastSyncRejectsValuesAboveTheSignedTimeTMaximum(): void
    {
        foreach (['9223372036854775808', '18446744073709551615'] as $invalid) {
            $this->writeValidGenerationWithLastSync($invalid);
            try {
                $this->takeover()->takeover($this->directory, false);
                self::fail('LAST_SYNC above the signed time_t maximum was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function lastSyncRejectsMalformedValues(): void
    {
        foreach (['', '-1', '+1', ' 1', '1 ', '1a'] as $invalid) {
            $this->writeValidGenerationWithLastSync($invalid);
            try {
                $this->takeover()->takeover($this->directory, false);
                self::fail('Malformed LAST_SYNC was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function rejectsMissingMalformedAndInconsistentInputs(): void
    {
        $takeover = $this->takeover();
        $this->expectException(RuntimeException::class);
        $takeover->takeover($this->directory . '/missing');
    }

    #[Test]
    public function rejectsInvalidStateAndMissingSnapshot(): void
    {
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/.udb_state', "FORMAT=1\nSTATE=BOOTSTRAPPING\nORIGIN=FRESH\nGENERATION=7\nLAST_SYNC=1\n");
        $this->expectException(RuntimeException::class);
        $this->takeover()->takeover($this->directory, false);
    }

    #[Test]
    public function rejectsDuplicateInvalidAndWrongGenerationRecords(): void
    {
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/udb_I.db', "; UDB Block I - Version 1\n; Generation: 7\n1.2.3.4::clones *5\n1.2.3.4::CLONES *5\n");
        $this->expectException(RuntimeException::class);
        $this->takeover()->takeover($this->directory, false);
    }

    #[Test]
    public function rejectsAnAlreadyLockedDirectory(): void
    {
        $this->writeValidGeneration();
        $lock = fopen($this->directory . '/.udb.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->expectException(RuntimeException::class);
            $this->takeover()->takeover($this->directory, false);
        } finally {
            fclose($lock);
        }
    }

    #[Test]
    public function rejectsUnsafeStateAndBlockFileForms(): void
    {
        $cases = $this->unsafeStateAndBlockFileMutations();

        foreach ($cases as $mutate) {
            $this->writeValidGeneration();
            $mutate($this->directory);
            try {
                $this->takeover()->takeover($this->directory, false);
                self::fail('Invalid snapshot was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function rejectsMissingSymlinkedAndRepeatedMetadata(): void
    {
        $cases = $this->metadataMutations();

        foreach ($cases as $mutate) {
            $this->writeValidGeneration();
            $mutate($this->directory);
            try {
                $this->takeover()->takeover($this->directory, false);
                self::fail('Invalid metadata was accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $this->writeValidGeneration();
        unlink($this->directory . '/udb_S.db');
        self::assertTrue(symlink($this->directory . '/udb_I.db', $this->directory . '/udb_S.db'));
        $this->expectException(RuntimeException::class);
        $this->takeover()->takeover($this->directory, false);
    }

    /** @return list<Closure(string): void> */
    private function unsafeStateAndBlockFileMutations(): array
    {
        return [
            static function (string $directory): void {
                file_put_contents($directory . '/.udb_state', "FORMAT=1\nFORMAT=1\nSTATE=READY\nORIGIN=FRESH\nGENERATION=7\nLAST_SYNC=1\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/.udb_state', "FORMAT=1\nSTATE=READY\nORIGIN=FRESH\nGENERATION=7\nLAST_SYNC=x\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/.udb_state', "FORMAT=1\nSTATE=READY\nORIGIN=FRESH\nGENERATION=18446744073709551616\nLAST_SYNC=1\n");
            },
            static function (string $directory): void {
                unlink($directory . '/udb_L.db');
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; Generation: 7\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; UDB Block C - Version 1\n; Generation: x\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; UDB Block C - Version 1\n; Generation: 18446744073709551616\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; UDB Block C - Version 1\n; Generation: 7\ninvalid\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_I.db', "; UDB Block I - Version 1\n; Generation: 7\nbad%ZZ::clones *5\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; UDB Block C - Version 1\n; Generation: 7\n" . str_repeat('a', 13000) . " x\n");
            },
        ];
    }

    /** @return list<Closure(string): void> */
    private function metadataMutations(): array
    {
        return [
            static function (string $directory): void {
                unlink($directory . '/.udb_state');
            },
            static function (string $directory): void {
                file_put_contents($directory . '/.udb_state', "; comment\n");
            },
            static function (string $directory): void {
                file_put_contents($directory . '/udb_C.db', "; UDB Block C - Version 1\n; UDB Block C - Version 1\n; Generation: 7\n");
            },
        ];
    }

    #[Test]
    public function acceptsSnapshotCommentsWithoutTreatingThemAsRecords(): void
    {
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/udb_I.db', "; UDB Block I - Version 1\n; Generation: 7\n; retained comment\n1.2.3.4::clones *5\n");

        self::assertSame(64, strlen($this->takeover()->takeover($this->directory, false)));
    }

    #[Test]
    public function canonicalizesSnapshotNumericValuesLikeUpstreamBeforePersisting(): void
    {
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/udb_I.db', "; UDB Block I - Version 1\n; Generation: 7\n1.2.3.4::clones *0005\n");

        $this->takeover()->takeover($this->directory);

        self::assertSame(['1.2.3.4::clones' => '*5'], $this->records('I'));
    }

    #[Test]
    public function acceptsDistinctCaseSensitiveSpamfilterPatternIdentities(): void
    {
        $this->writeValidGeneration();
        file_put_contents(
            $this->directory . '/udb_K.db',
            "; UDB Block K - Version 1\n; Generation: 7\nF::b64%3AQWJj::reason first\nF::b64%3AqWJj::reason second\n",
        );

        $fingerprint = $this->takeover()->takeover($this->directory);

        self::assertSame(64, strlen($fingerprint));
        $authority = $this->entityManager->find(UdbAuthorityState::class, 1);
        self::assertInstanceOf(UdbAuthorityState::class, $authority);
        self::assertTrue($authority->isApproved());
    }

    #[Test]
    public function rejectsACompleteSpamfilterWithAnInvalidRegex(): void
    {
        $this->writeValidGeneration();
        $pattern = 'b64%3A' . base64_encode('(');
        file_put_contents(
            $this->directory . '/udb_K.db',
            "; UDB Block K - Version 1\n; Generation: 7\n"
            . "F::{$pattern}::match-type regex\n"
            . "F::{$pattern}::targets c\n"
            . "F::{$pattern}::action kill\n"
            . "F::{$pattern}::reason blocked\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid aggregate');
        $this->takeover()->takeover($this->directory, false);
    }

    #[Test]
    public function rejectsCanonicalPathsThatDoNotMatchTheBlockSchema(): void
    {
        $this->writeValidGeneration();
        file_put_contents($this->directory . '/udb_I.db', "; UDB Block I - Version 1\n; Generation: 7\n1.2.3.4::clones invalid\n");

        $this->expectException(RuntimeException::class);
        $this->takeover()->takeover($this->directory, false);
    }

    #[Test]
    public function rebuildsNickRecordsFromSqlDuringDryRun(): void
    {
        $nick = new NickProjection(1, 'alice', '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe', null, false, null);
        $repository = $this->createStub(NickProjectionQuery::class);
        $repository->method('all')->willReturn([$nick]);

        $this->writeValidGeneration();
        self::assertSame(64, strlen($this->takeover($repository)->takeover($this->directory, false)));
    }

    #[Test]
    public function rejectsInvalidRecordsProducedByTheSqlExporter(): void
    {
        $nick = new NickProjection(1, "invalid\0nick", '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe', null, false, null);
        $repository = $this->createStub(NickProjectionQuery::class);
        $repository->method('all')->willReturn([$nick]);
        $this->writeValidGeneration();

        $this->expectException(RuntimeException::class);
        $this->takeover($repository)->takeover($this->directory, false);
    }

    private function takeover(?NickProjectionQuery $nickRepository = null): UdbOfflineTakeover
    {
        return new UdbOfflineTakeover($this->entityManager, new UdbRecordExporter(
            $nickRepository ?? $this->createStub(NickProjectionQuery::class),
            $this->createStub(ChannelProjectionQuery::class),
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        ));
    }

    private function writeValidGeneration(string $generation = '7'): void
    {
        file_put_contents($this->directory . '/.udb_state', "FORMAT=1\nSTATE=READY\nORIGIN=FRESH\nGENERATION={$generation}\nLAST_SYNC=1\n");
        foreach (['N' => '', 'C' => '', 'I' => "1.2.3.4::clones *5\n", 'S' => "propagator hub1.example, hub2.example\n", 'L' => "hub1.example::options *1\n", 'K' => ''] as $block => $records) {
            file_put_contents($this->directory . '/udb_' . $block . '.db', '; UDB Block ' . $block . " - Version 1\n; Generation: {$generation}\n" . $records);
        }
    }

    private function writeValidGenerationWithLastSync(string $lastSync, string $generation = '7'): void
    {
        file_put_contents($this->directory . '/.udb_state', "FORMAT=1\nSTATE=READY\nORIGIN=FRESH\nGENERATION={$generation}\nLAST_SYNC={$lastSync}\n");
        foreach (['N' => '', 'C' => '', 'I' => "1.2.3.4::clones *5\n", 'S' => "propagator hub1.example, hub2.example\n", 'L' => "hub1.example::options *1\n", 'K' => ''] as $block => $records) {
            file_put_contents($this->directory . '/udb_' . $block . '.db', '; UDB Block ' . $block . " - Version 1\n; Generation: {$generation}\n" . $records);
        }
    }

    /** @return array<string, string> */
    private function records(string $block): array
    {
        $rows = $this->entityManager
            ->createQuery('SELECT r.path, r.value FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();
        $records = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('path', $row);
            self::assertArrayHasKey('value', $row);
            self::assertIsString($row['path']);
            self::assertIsString($row['value']);
            $records[$row['path']] = $row['value'];
        }

        return $records;
    }
}
