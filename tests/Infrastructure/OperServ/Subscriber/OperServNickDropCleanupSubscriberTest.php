<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\OperServNickDropCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServNickDropCleanupSubscriber::class)]
final class OperServNickDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToNickDropEvent(): void
    {
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

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(12345);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(12345);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo);
        $subscriber->onNickDrop($event);
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

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(999);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(999);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo);
        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function clearsGlineCreatorNickId(): void
    {
        $event = new NickDropEvent(
            nickId: 42,
            nickname: 'GlineCreator',
            nicknameLower: 'glinecreator',
            reason: 'manual',
        );

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(42);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(42);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo);
        $subscriber->onNickDrop($event);
    }
}
