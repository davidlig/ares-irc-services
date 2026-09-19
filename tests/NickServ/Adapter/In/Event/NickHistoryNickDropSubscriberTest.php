<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\NickServ\Adapter\In\Event\NickHistoryNickDropSubscriber;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickHistoryNickDropSubscriber::class)]
final class NickHistoryNickDropSubscriberTest extends TestCase
{
    private MockObject&NickHistoryRepositoryInterface $historyRepository;

    private NickHistoryNickDropSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);

        $this->subscriber = new NickHistoryNickDropSubscriber(
            $this->historyRepository,
        );
    }

    #[Test]
    public function subscribesToNickDropEvent(): void
    {
        $this->historyRepository->expects(self::never())->method('deleteByNickId');

        self::assertSame(
            [NickDropCleanupEvent::class => ['onNickDrop', 0]],
            NickHistoryNickDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesHistoryForDroppedNick(): void
    {
        $event = new NickDropCleanupEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        );

        $this->historyRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(12345);

        $this->subscriber->onNickDrop($event);
    }

    #[Test]
    public function deletesHistoryForDifferentNickIds(): void
    {
        $event = new NickDropCleanupEvent(
            nickId: 999,
            nickname: 'AnotherUser',
            nicknameLower: 'anotheruser',
            reason: 'inactivity',
            occurredAt: new DateTimeImmutable(),
        );

        $this->historyRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(999);

        $this->subscriber->onNickDrop($event);
    }
}
