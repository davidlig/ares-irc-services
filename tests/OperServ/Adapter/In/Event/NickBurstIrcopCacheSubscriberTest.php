<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use App\OperServ\Adapter\In\Event\NickBurstIrcopCacheSubscriber;
use App\OperServ\Adapter\Out\Persistence\Doctrine\BurstIrcopCache;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(NickBurstIrcopCacheSubscriber::class)]
#[CoversClass(BurstIrcopCache::class)]
final class NickBurstIrcopCacheSubscriberTest extends TestCase
{
    #[Test]
    public function preloadsBatchAndClearsSnapshotOnFinish(): void
    {
        $assignment = OperIrcop::create(new DateTimeImmutable('2026-01-01'), 10, OperRole::create('Admin', 'Admin role'));
        $repository = $this->createMock(OperIrcopRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNickIds')->with([10, 20])->willReturn([$assignment]);
        $cache = new BurstIrcopCache();
        $subscriber = new NickBurstIrcopCacheSubscriber($repository, $cache);
        $token = new stdClass();

        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10, 20], $token));

        self::assertSame([true, $assignment], $cache->lookup(10));
        self::assertSame([true, null], $cache->lookup(20));
        self::assertSame([false, null], $cache->lookup(30));

        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($token));
        self::assertSame([false, null], $cache->lookup(10));
    }

    #[Test]
    public function emptyBatchStillHasLifecycleAndLeavesNoSnapshot(): void
    {
        $repository = $this->createMock(OperIrcopRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNickIds')->with([])->willReturn([]);
        $cache = new BurstIrcopCache();
        $subscriber = new NickBurstIrcopCacheSubscriber($repository, $cache);
        $token = new stdClass();

        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([], $token));
        self::assertSame([false, null], $cache->lookup(10));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($token));
        self::assertSame([false, null], $cache->lookup(10));
    }

    #[Test]
    public function failedPreloadCleansUpTheStartedSnapshot(): void
    {
        $repository = $this->createMock(OperIrcopRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNickIds')->willThrowException(new RuntimeException('query failed'));
        $cache = new BurstIrcopCache();
        $subscriber = new NickBurstIrcopCacheSubscriber($repository, $cache);
        $token = new stdClass();

        try {
            $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10], $token));
            self::fail('Expected preload failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('query failed', $exception->getMessage());
        }

        self::assertSame([false, null], $cache->lookup(10));
    }

    #[Test]
    public function failedNestedPreloadAndItsLaterFinishDoNotPopOuterSnapshot(): void
    {
        $assignment = OperIrcop::create(new DateTimeImmutable('2026-01-01'), 10, OperRole::create('Admin', 'Admin role'));
        $repository = $this->createMock(OperIrcopRepositoryInterface::class);
        $preloads = 0;
        $repository->expects(self::exactly(2))->method('findByNickIds')->willReturnCallback(
            static function () use (&$preloads, $assignment): array {
                if (2 === ++$preloads) {
                    throw new RuntimeException('nested query failed');
                }

                return [$assignment];
            },
        );
        $cache = new BurstIrcopCache();
        $subscriber = new NickBurstIrcopCacheSubscriber($repository, $cache);
        $outerToken = new stdClass();
        $innerToken = new stdClass();

        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10], $outerToken));
        try {
            $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([20], $innerToken));
            self::fail('Expected nested preload failure to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('nested query failed', $exception->getMessage());
        }

        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($innerToken));

        self::assertSame([true, $assignment], $cache->lookup(10));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent($outerToken));
        self::assertSame([false, null], $cache->lookup(10));
    }

    #[Test]
    public function finishForUnknownTokenDoesNotPopAnActiveSnapshot(): void
    {
        $assignment = OperIrcop::create(new DateTimeImmutable('2026-01-01'), 10, OperRole::create('Admin', 'Admin role'));
        $repository = $this->createMock(OperIrcopRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNickIds')->willReturn([$assignment]);
        $cache = new BurstIrcopCache();
        $subscriber = new NickBurstIrcopCacheSubscriber($repository, $cache);
        $token = new stdClass();

        $subscriber->onBatchStarted(new NickBurstBatchStartedEvent([10], $token));
        $subscriber->onBatchFinished(new NickBurstBatchFinishedEvent(new stdClass()));

        self::assertSame([true, $assignment], $cache->lookup(10));
    }

    #[Test]
    public function subscriberListensToBothBatchLifecycleEvents(): void
    {
        self::assertSame([
            NickBurstBatchStartedEvent::class => 'onBatchStarted',
            NickBurstBatchFinishedEvent::class => 'onBatchFinished',
        ], NickBurstIrcopCacheSubscriber::getSubscribedEvents());
    }
}
