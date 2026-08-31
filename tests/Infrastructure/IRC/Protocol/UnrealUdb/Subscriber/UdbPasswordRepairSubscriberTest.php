<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbPasswordRepairSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

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

    private function createSubscriber(
        ?UdbRecordWriterInterface $writer = null,
        array $nicks = [],
        array $store = [],
    ): UdbPasswordRepairSubscriber {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
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

    private function createExporter(RegisteredNickRepositoryInterface $nickRepository): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $nickRepository,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(GlineRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
    }

    private function createNick(string $nickname, string $passwordHash = self::BCRYPT_HASH): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, $passwordHash, 'owner@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($nick, 7);

        return $nick;
    }

    private function createForbidden(string $nickname): RegisteredNick
    {
        return RegisteredNick::createForbidden($nickname, 'forbidden', 'en');
    }
}
