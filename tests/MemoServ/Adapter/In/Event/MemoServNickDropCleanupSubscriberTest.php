<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Event\MemoServNickDropCleanupSubscriber;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoDataHandler;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServNickDropCleanupSubscriber::class)]
final class MemoServNickDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToThePublishedNickCleanupEvent(): void
    {
        self::assertSame(
            [NickDropCleanupEvent::class => ['onNickDrop', 0]],
            MemoServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsThePublishedEventToTheCleanupUseCase(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('deleteAllForNick')->with(12345);
        $ignoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepository->expects(self::once())->method('deleteAllForNick')->with(12345);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('deleteAllForNick')->with(12345);

        $subscriber = new MemoServNickDropCleanupSubscriber(
            new CleanupNickMemoDataHandler($memoRepository, $ignoreRepository, $settingsRepository),
        );

        $subscriber->onNickDrop(new NickDropCleanupEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        ));
    }
}
