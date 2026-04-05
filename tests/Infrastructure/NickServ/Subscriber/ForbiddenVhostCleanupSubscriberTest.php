<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\ForbiddenVhostCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenVhostCleanupSubscriber::class)]
final class ForbiddenVhostCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectMapping(): void
    {
        $events = ForbiddenVhostCleanupSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NickDropEvent::class, $events);
        self::assertSame(['onNickDrop', 0], $events[NickDropEvent::class]);
    }

    #[Test]
    public function onNickDropClearsCreatorReferences(): void
    {
        $repository = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $subscriber = new ForbiddenVhostCleanupSubscriber($repository);

        $event = new NickDropEvent(
            nickId: 123,
            nickname: 'TestNick',
            nicknameLower: 'testnick',
            reason: 'manual'
        );

        $repository
            ->expects(self::once())
            ->method('clearCreatedByNickId')
            ->with(123);

        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function onNickDropClearsCreatorReferencesForDifferentNickIds(): void
    {
        $repository = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $subscriber = new ForbiddenVhostCleanupSubscriber($repository);

        $event = new NickDropEvent(
            nickId: 456,
            nickname: 'OtherNick',
            nicknameLower: 'othernick',
            reason: 'manual'
        );

        $repository
            ->expects(self::once())
            ->method('clearCreatedByNickId')
            ->with(456);

        $subscriber->onNickDrop($event);
    }
}
