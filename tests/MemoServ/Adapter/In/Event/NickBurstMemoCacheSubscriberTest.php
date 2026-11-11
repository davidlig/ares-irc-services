<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Event\NickBurstMemoCacheSubscriber;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\BurstMemoNoticeCache;
use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(NickBurstMemoCacheSubscriber::class)]
#[CoversClass(BurstMemoNoticeCache::class)]
final class NickBurstMemoCacheSubscriberTest extends TestCase
{
    #[Test]
    public function emptyCacheAndInvalidatedNestedFramesBehavePredictably(): void
    {
        $cache = new BurstMemoNoticeCache();
        self::assertSame([], $cache->activeNickIds());
        self::assertFalse($cache->includesNickId(1));
        $cache->replaceActiveCounts([1 => 4]);
        $outer = new stdClass();
        $inner = new stdClass();
        $cache->beginBatch([1], [1 => 2, 3 => 9], $outer);
        $cache->beginBatch([2], [2 => 3], $inner);
        $cache->invalidateCounts();
        self::assertNull($cache->countFor(2));
        $cache->replaceActiveCounts([2 => 4, 3 => 9]);
        self::assertSame(4, $cache->countFor(2));
        $cache->finishBatch($inner);
        self::assertNull($cache->countFor(1));
        $cache->replaceActiveCounts([1 => 5]);
        self::assertSame(5, $cache->countFor(1));
    }

    #[Test]
    public function prefetchesOneGroupedCountSetAndClearsItAtBatchEnd(): void
    {
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::once())->method('countUnreadByTargetNickIds')->with([10, 11])->willReturn([10 => 3]);
        $cache = new BurstMemoNoticeCache();
        $subscriber = new NickBurstMemoCacheSubscriber($repository, $cache);

        self::assertSame([
            NickBurstBatchStartedEvent::class => ['onBatchStarted', 0],
            NickBurstBatchFinishedEvent::class => ['onBatchFinished', 0],
        ], NickBurstMemoCacheSubscriber::getSubscribedEvents());
        $token = new stdClass();
        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10, 11], $token));
        self::assertSame(3, $cache->countFor(10));
        self::assertSame(0, $cache->countFor(11));

        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($token));
        self::assertFalse($cache->hasActiveBatch());
        self::assertNull($cache->countFor(10));
    }

    #[Test]
    public function emptyAndReentrantBatchesRemainScopedAndRestoreOuterCounts(): void
    {
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::once())->method('countUnreadByTargetNickIds')->with([20])->willReturn([20 => 4]);
        $cache = new BurstMemoNoticeCache();
        $subscriber = new NickBurstMemoCacheSubscriber($repository, $cache);

        $emptyToken = new stdClass();
        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([], $emptyToken));
        self::assertSame([], $cache->activeNickIds());
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($emptyToken));

        $outerToken = new stdClass();
        $innerToken = new stdClass();
        $cache->beginBatch([10], [10 => 1], $outerToken);
        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([20], $innerToken));
        self::assertSame(4, $cache->countFor(20));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent(new stdClass()));
        self::assertSame(4, $cache->countFor(20));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($innerToken));
        self::assertSame(1, $cache->countFor(10));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($outerToken));
        self::assertFalse($cache->hasActiveBatch());
    }

    #[Test]
    public function finishForBatchWhosePrefetchFailedDoesNotPopOuterBatch(): void
    {
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('countUnreadByTargetNickIds')
            ->willReturnCallback(static function (array $nickIds): array {
                if ([10] === $nickIds) {
                    return [10 => 1];
                }

                throw new RuntimeException('Prefetch failed.');
            });
        $cache = new BurstMemoNoticeCache();
        $subscriber = new NickBurstMemoCacheSubscriber($repository, $cache);
        $outerToken = new stdClass();
        $failedToken = new stdClass();
        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10], $outerToken));

        try {
            $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([20], $failedToken));
            self::fail('Expected grouped prefetch to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Prefetch failed.', $exception->getMessage());
        }

        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($failedToken));
        self::assertSame(1, $cache->countFor(10));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($outerToken));
        self::assertFalse($cache->hasActiveBatch());
    }
}
