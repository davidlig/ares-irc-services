<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\Service\NickHistoryService;
use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Event\NickEmailChangedEvent;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Event\NickRecoveredEvent;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Event\NickUnsuspendedEvent;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\NickHistorySubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickHistorySubscriber::class)]
final class NickHistorySubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = NickHistorySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NickSuspendedEvent::class, $events);
        self::assertArrayHasKey(NickUnsuspendedEvent::class, $events);
        self::assertArrayHasKey(NickPasswordChangedEvent::class, $events);
        self::assertArrayHasKey(NickEmailChangedEvent::class, $events);
        self::assertArrayHasKey(NickRecoveredEvent::class, $events);
    }

    #[Test]
    public function onNickSuspendedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SUSPEND', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('Spamming', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('192.168.1.100', $extraData['ip']);
                self::assertSame('oper@example.com', $extraData['host']);
                self::assertSame('7d', $extraData['duration']);
                self::assertSame('2024-01-22 10:30:00', $extraData['expires_at']);

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickSuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            reason: 'Spamming',
            duration: '7d',
            expiresAt: new DateTimeImmutable('2024-01-22 10:30:00'),
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: $occurredAt,
        );

        $subscriber->onNickSuspended($event);
    }

    #[Test]
    public function onNickSuspendedWithoutDuration(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SUSPEND', $history->getAction());
                $extraData = $history->getExtraData();
                self::assertArrayNotHasKey('duration', $extraData);
                self::assertArrayNotHasKey('expires_at', $extraData);

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickSuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            reason: 'Permanent ban',
            duration: null,
            expiresAt: null,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickSuspended($event);
    }

    #[Test]
    public function onNickUnsuspendedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('UNSUSPEND', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('history.message.unsuspend', $history->getMessage());

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickUnsuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickUnsuspended($event);
    }

    #[Test]
    public function onNickPasswordChangedByOwner(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SET_PASSWORD', $history->getAction());
                self::assertSame('TestNick', $history->getPerformedBy());
                self::assertSame(123, $history->getPerformedByNickId());
                self::assertSame('history.message.password_changed_owner', $history->getMessage());

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickPasswordChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            changedByOwner: true,
            performedBy: 'TestNick',
            performedByNickId: 123,
            performedByIp: '192.168.1.1',
            performedByHost: 'test@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickPasswordChanged($event);
    }

    #[Test]
    public function onNickPasswordChangedByOperator(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SASET_PASSWORD', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('history.message.password_changed_operator', $history->getMessage());

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickPasswordChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            changedByOwner: false,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickPasswordChanged($event);
    }

    #[Test]
    public function onNickEmailChangedByOwner(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SET_EMAIL', $history->getAction());
                self::assertSame('TestNick', $history->getPerformedBy());
                self::assertSame(123, $history->getPerformedByNickId());
                self::assertSame('history.message.email_changed_owner', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('old@example.com', $extraData['old_email']);
                self::assertSame('new@example.com', $extraData['new_email']);

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickEmailChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            oldEmail: 'old@example.com',
            newEmail: 'new@example.com',
            changedByOwner: true,
            performedBy: 'TestNick',
            performedByNickId: 123,
            performedByIp: '192.168.1.1',
            performedByHost: 'test@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickEmailChanged($event);
    }

    #[Test]
    public function onNickEmailChangedByOperator(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('SASET_EMAIL', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('history.message.email_changed_operator', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertNull($extraData['old_email']);
                self::assertSame('oper@set.email', $extraData['new_email']);

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickEmailChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            oldEmail: null,
            newEmail: 'oper@set.email',
            changedByOwner: false,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickEmailChanged($event);
    }

    #[Test]
    public function onNickRecoveredRecordsHistory(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (NickHistory $history) {
                self::assertSame(123, $history->getNickId());
                self::assertSame('RECOVER', $history->getAction());
                self::assertSame('TestNick', $history->getPerformedBy());
                self::assertNull($history->getPerformedByNickId());
                self::assertSame('history.message.recover', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('email_token', $extraData['method']);

                return true;
            }));

        $historyService = new NickHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new NickHistorySubscriber($historyService, $nickRepo);

        $event = new NickRecoveredEvent(
            nickId: 123,
            nickname: 'TestNick',
            method: 'email_token',
            performedBy: 'TestNick',
            performedByNickId: null,
            performedByIp: '192.168.1.1',
            performedByHost: 'test@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onNickRecovered($event);
    }
}
