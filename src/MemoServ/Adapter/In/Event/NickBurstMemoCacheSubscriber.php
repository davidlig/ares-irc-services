<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\BurstMemoNoticeCache;
use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class NickBurstMemoCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private BurstMemoNoticeCache $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickBurstBatchStartedEvent::class => ['onBatchStarted', 0],
            NickBurstBatchFinishedEvent::class => ['onBatchFinished', 0],
        ];
    }

    public function onBatchStarted(NickBurstBatchStartedEvent $event): void
    {
        $counts = [] === $event->nickIds ? [] : $this->memoRepository->countUnreadByTargetNickIds($event->nickIds);
        $this->cache->beginBatch($event->nickIds, $counts, $event->token);
    }

    public function onBatchFinished(NickBurstBatchFinishedEvent $event): void
    {
        $this->cache->finishBatch($event->token);
    }
}
