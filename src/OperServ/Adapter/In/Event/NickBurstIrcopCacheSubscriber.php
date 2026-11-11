<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use App\OperServ\Adapter\Out\Persistence\Doctrine\BurstIrcopCache;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class NickBurstIrcopCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private BurstIrcopCache $burstCache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickBurstBatchStartedEvent::class => 'onBatchStarted',
            NickBurstBatchFinishedEvent::class => 'onBatchFinished',
        ];
    }

    public function onBatchStarted(NickBurstBatchStartedEvent $event): void
    {
        $this->burstCache->beginBatch($event->token);
        try {
            $assignments = $this->ircopRepository->findByNickIds($event->nickIds);
            $this->burstCache->primeBatch($event->token, $assignments, $event->nickIds);
        } catch (Throwable $exception) {
            $this->burstCache->finishBatch($event->token);
            throw $exception;
        }
    }

    public function onBatchFinished(NickBurstBatchFinishedEvent $event): void
    {
        $this->burstCache->finishBatch($event->token);
    }
}
