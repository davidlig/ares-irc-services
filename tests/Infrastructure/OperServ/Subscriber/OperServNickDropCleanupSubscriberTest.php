<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\OperServNickDropCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServNickDropCleanupSubscriber::class)]
final class OperServNickDropCleanupSubscriberTest extends TestCase
{
    private OperIrcopRepositoryInterface&MockObject $operIrcopRepository;

    private OperServNickDropCleanupSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->operIrcopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $this->subscriber = new OperServNickDropCleanupSubscriber($this->operIrcopRepository);
    }

    #[Test]
    public function subscribesToNickDropEvent(): void
    {
        $this->operIrcopRepository->expects(self::never())->method('deleteByNickId');
        self::assertSame(
            [NickDropEvent::class => ['onNickDrop', 0]],
            OperServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesIrcopEntryForDroppedNick(): void
    {
        $event = new NickDropEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
        );

        $this->operIrcopRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(12345);

        $this->subscriber->onNickDrop($event);
    }

    #[Test]
    public function deletesIrcopEntryForDroppedNickFromInactivity(): void
    {
        $event = new NickDropEvent(
            nickId: 999,
            nickname: 'OldUser',
            nicknameLower: 'olduser',
            reason: 'inactivity',
        );

        $this->operIrcopRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(999);

        $this->subscriber->onNickDrop($event);
    }
}
