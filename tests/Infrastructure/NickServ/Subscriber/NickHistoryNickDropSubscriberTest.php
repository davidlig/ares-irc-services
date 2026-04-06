<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\NickHistoryNickDropSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickHistoryNickDropSubscriber::class)]
final class NickHistoryNickDropSubscriberTest extends TestCase
{
    private NickHistoryRepositoryInterface&MockObject $historyRepository;

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
            [NickDropEvent::class => ['onNickDrop', 0]],
            NickHistoryNickDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesHistoryForDroppedNick(): void
    {
        $event = new NickDropEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
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
        $event = new NickDropEvent(
            nickId: 999,
            nickname: 'AnotherUser',
            nicknameLower: 'anotheruser',
            reason: 'inactivity',
        );

        $this->historyRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(999);

        $this->subscriber->onNickDrop($event);
    }
}
