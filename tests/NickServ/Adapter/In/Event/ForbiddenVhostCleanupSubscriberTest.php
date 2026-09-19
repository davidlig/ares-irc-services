<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\NickServ\Adapter\In\Event\ForbiddenVhostCleanupSubscriber;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use DateTimeImmutable;
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

        self::assertSame(
            [NickDropCleanupEvent::class => ['onNickDrop', 0]],
            $events,
        );
    }

    #[Test]
    public function onNickDropClearsCreatorReferences(): void
    {
        $repository = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $subscriber = new ForbiddenVhostCleanupSubscriber($repository);

        $event = new NickDropCleanupEvent(
            nickId: 123,
            nickname: 'TestNick',
            nicknameLower: 'testnick',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
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

        $event = new NickDropCleanupEvent(
            nickId: 456,
            nickname: 'OtherNick',
            nicknameLower: 'othernick',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        );

        $repository
            ->expects(self::once())
            ->method('clearCreatedByNickId')
            ->with(456);

        $subscriber->onNickDrop($event);
    }
}
