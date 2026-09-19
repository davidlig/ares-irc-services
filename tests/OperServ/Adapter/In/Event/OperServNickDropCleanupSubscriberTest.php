<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\OperServ\Adapter\In\Event\OperServNickDropCleanupSubscriber;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\Port\Out\MotdRepository;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
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
            [NickDropCleanupEvent::class => ['onNickDrop', 0]],
            OperServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesIrcopEntryForDroppedNick(): void
    {
        $event = new NickDropCleanupEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        );

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepository::class);
        $motdRepo = $this->createMock(MotdRepository::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(12345);
        $operIrcopRepo
            ->expects(self::once())
            ->method('clearAddedById')
            ->with(12345);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorAccountId')
            ->with(12345);

        $motdRepo
            ->expects(self::once())
            ->method('deleteByCreatorAccountId')
            ->with(12345);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo, $motdRepo);
        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function deletesIrcopEntryForDroppedNickFromInactivity(): void
    {
        $event = new NickDropCleanupEvent(
            nickId: 999,
            nickname: 'OldUser',
            nicknameLower: 'olduser',
            reason: 'inactivity',
            occurredAt: new DateTimeImmutable(),
        );

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepository::class);
        $motdRepo = $this->createMock(MotdRepository::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(999);
        $operIrcopRepo
            ->expects(self::once())
            ->method('clearAddedById')
            ->with(999);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorAccountId')
            ->with(999);

        $motdRepo
            ->expects(self::once())
            ->method('deleteByCreatorAccountId')
            ->with(999);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo, $motdRepo);
        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function clearsGlineCreatorNickId(): void
    {
        $event = new NickDropCleanupEvent(
            nickId: 42,
            nickname: 'GlineCreator',
            nicknameLower: 'glinecreator',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        );

        $operIrcopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $glineRepo = $this->createMock(GlineRepository::class);
        $motdRepo = $this->createMock(MotdRepository::class);

        $operIrcopRepo
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(42);
        $operIrcopRepo
            ->expects(self::once())
            ->method('clearAddedById')
            ->with(42);

        $glineRepo
            ->expects(self::once())
            ->method('clearCreatorAccountId')
            ->with(42);

        $motdRepo
            ->expects(self::once())
            ->method('deleteByCreatorAccountId')
            ->with(42);

        $subscriber = new OperServNickDropCleanupSubscriber($operIrcopRepo, $glineRepo, $motdRepo);
        $subscriber->onNickDrop($event);
    }
}
