<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\ForbiddenVhostCleanupSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenVhostCleanupSubscriber::class)]
final class ForbiddenVhostCleanupSubscriberTest extends TestCase
{
    private ForbiddenVhostRepositoryInterface&MockObject $repository;

    private ForbiddenVhostCleanupSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $this->subscriber = new ForbiddenVhostCleanupSubscriber($this->repository);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ForbiddenVhostCleanupSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NickDropEvent::class, $events);
        self::assertSame(['onNickDrop', 0], $events[NickDropEvent::class]);
    }

    public function testOnNickDropClearsCreatorReferences(): void
    {
        $event = new NickDropEvent(
            nickId: 123,
            nickname: 'TestNick',
            nicknameLower: 'testnick',
            reason: 'manual'
        );

        $this->repository
            ->expects(self::once())
            ->method('clearCreatedByNickId')
            ->with(123);

        $this->subscriber->onNickDrop($event);
    }

    public function testOnNickDropClearsCreatorReferencesForDifferentNickIds(): void
    {
        $event = new NickDropEvent(
            nickId: 456,
            nickname: 'OtherNick',
            nicknameLower: 'othernick',
            reason: 'manual'
        );

        $this->repository
            ->expects(self::once())
            ->method('clearCreatedByNickId')
            ->with(456);

        $this->subscriber->onNickDrop($event);
    }
}
