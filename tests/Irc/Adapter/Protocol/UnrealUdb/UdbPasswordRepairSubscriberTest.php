<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbPasswordRepairSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(UdbPasswordRepairSubscriber::class)]
final class UdbPasswordRepairSubscriberTest extends TestCase
{
    private const string BCRYPT_HASH = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';

    private const string SHA256_HASH = 'sha256:6ca13d52ca70c883e0f0bb101e425a89e8624de51db2d2392593af6a84118090';

    #[Test]
    public function subscribedEventsRunAfterTheStoreInitializer(): void
    {
        self::assertSame([
            NetworkSyncCompleteEvent::class => ['onNetworkSyncComplete', -10],
        ], UdbPasswordRepairSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function onNetworkSyncCompleteRunsTheRepair(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true);

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: []);
        $subscriber->onNetworkSyncComplete(new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001'));
    }

    #[Test]
    public function insertsMissingPassRecordsFromBcryptSqlHashes(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'davidlig::pass', 'crypt:' . self::BCRYPT_HASH);

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: []);

        self::assertSame(1, $subscriber->repair());
    }

    #[Test]
    public function replacesLegacySha256RecordsWithTheSqlProjection(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'davidlig::pass', 'crypt:' . self::BCRYPT_HASH);

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: [
            'davidlig::pass' => self::SHA256_HASH,
        ]);

        self::assertSame(1, $subscriber->repair());
    }

    #[Test]
    public function matchingStoreKeysAreCaseInsensitive(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'DavidLig::pass', 'crypt:' . self::BCRYPT_HASH);

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('DavidLig')], store: [
            'davidlig::pass' => self::SHA256_HASH,
        ]);

        self::assertSame(1, $subscriber->repair());
    }

    #[Test]
    public function leavesExplicitCryptRecordsUntouched(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: [
            'davidlig::pass' => 'crypt:$6$other',
        ]);

        self::assertSame(0, $subscriber->repair());
    }

    #[Test]
    public function leavesExplicitArgon2idRecordsUntouched(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: [
            'davidlig::pass' => 'argon2id:$argon2id$othervalue',
        ]);

        self::assertSame(0, $subscriber->repair());
    }

    #[Test]
    public function keepsTheRecordWhenTheStoreAlreadyMatchesTheProjection(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig')], store: [
            'davidlig::pass' => 'crypt:' . self::BCRYPT_HASH,
        ]);

        self::assertSame(0, $subscriber->repair());
    }

    #[Test]
    public function deletesPassRecordsWhenSqlHasNoHash(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'oldnick::pass');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createForbidden('oldnick')], store: [
            'oldnick::pass' => 'crypt:$6$rounds=656000$salt$hash',
        ]);

        self::assertSame(1, $subscriber->repair());
    }

    #[Test]
    public function skipsDeletingWhenTheStoreHasNoRecordForHashlessNicks(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createForbidden('oldnick')], store: []);

        self::assertSame(0, $subscriber->repair());
    }

    #[Test]
    public function skipsNicksWhoseSqlHashCannotBeProjected(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick('davidlig', passwordHash: 'md5:deadbeef')], store: []);

        self::assertSame(0, $subscriber->repair());
    }

    #[Test]
    public function skipsNicksWhosePathCannotBeEncoded(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');
        $writer->expects($this->never())->method('delete');

        $subscriber = $this->createSubscriber(writer: $writer, nicks: [$this->createNick(str_repeat('a', 4609))], store: []);

        self::assertSame(0, $subscriber->repair());
    }

    /**
     * @param list<NickProjection>  $nicks
     * @param array<string, string> $store
     */
    private function createSubscriber(
        ?UdbRecordWriterInterface $writer = null,
        array $nicks = [],
        array $store = [],
    ): UdbPasswordRepairSubscriber {
        $nickRepository = $this->createStub(NickProjectionQuery::class);
        $nickRepository->method('all')->willReturn($nicks);

        $recordRepository = $this->createStub(UdbRecordRepositoryInterface::class);
        $recordRepository->method('recordsByBlock')->willReturnMap([['N', $store]]);

        return new UdbPasswordRepairSubscriber(
            $nickRepository,
            $recordRepository,
            $writer ?? $this->createStub(UdbRecordWriterInterface::class),
            $this->createExporter($nickRepository),
            new NullLogger(),
        );
    }

    private function createExporter(NickProjectionQuery $nickRepository): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $nickRepository,
            $this->createStub(ChannelProjectionQuery::class),
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
    }

    private function createNick(string $nickname, string $passwordHash = self::BCRYPT_HASH): NickProjection
    {
        return new NickProjection(7, $nickname, $passwordHash, null);
    }

    private function createForbidden(string $nickname): NickProjection
    {
        return new NickProjection(7, $nickname, null, null);
    }
}
