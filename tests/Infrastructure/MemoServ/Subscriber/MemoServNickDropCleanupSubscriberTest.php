<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Subscriber;

use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Infrastructure\MemoServ\Subscriber\MemoServNickDropCleanupSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(MemoServNickDropCleanupSubscriber::class)]
final class MemoServNickDropCleanupSubscriberTest extends TestCase
{
    private MemoRepositoryInterface&MockObject $memoRepository;

    private MemoIgnoreRepositoryInterface&MockObject $memoIgnoreRepository;

    private MemoSettingsRepositoryInterface&MockObject $memoSettingsRepository;

    private MemoServNickDropCleanupSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $this->memoIgnoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $this->memoSettingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);

        $this->subscriber = new MemoServNickDropCleanupSubscriber(
            $this->memoRepository,
            $this->memoIgnoreRepository,
            $this->memoSettingsRepository,
        );
    }

    #[Test]
    public function subscribesToNickDropEvent(): void
    {
        self::assertSame(
            [NickDropEvent::class => ['onNickDrop', 0]],
            MemoServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesAllMemoDataForDroppedNick(): void
    {
        $event = new NickDropEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('deleteAllForNick')
            ->with(12345);

        $this->memoIgnoreRepository
            ->expects(self::once())
            ->method('deleteAllForNick')
            ->with(12345);

        $this->memoSettingsRepository
            ->expects(self::once())
            ->method('deleteAllForNick')
            ->with(12345);

        $this->subscriber->onNickDrop($event);
    }
}
