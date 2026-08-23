<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\ChanServ\Event\ChannelTopiclockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbChannelModesFormatter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function explode;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

final class UdbChannelSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'C';

    /** @var array<string, true> */
    private array $receivedKeys = [];

    private bool $syncing = false;

    /** @var array<string, true> */
    private array $deletedRoots = [];

    /**
     * Reconciliation actions queued while a block sync is in flight.
     * Key: record path without the block prefix. Value: insert value, or
     * false for a delete. UDB rejects real-time INS/DEL while a staged
     * transaction is active, so writes are deferred until END/FDR.
     *
     * @var array<string, string|false>
     */
    private array $pendingActions = [];

    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private UdbRecordWriterInterface $recordWriter,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelLookupPort $channelLookup,
        private UdbChannelModesFormatter $modesFormatter = new UdbChannelModesFormatter(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelFounderChangedEvent::class => 'onChannelFounderChanged',
            ChannelForbiddenEvent::class => 'onChannelForbidden',
            ChannelUnforbiddenEvent::class => 'onChannelUnforbidden',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelAccessChangedEvent::class => 'onChannelAccessChanged',
            ChannelMlockUpdatedEvent::class => 'onChannelMlockUpdated',
            ChannelTopiclockUpdatedEvent::class => 'onChannelTopiclockUpdated',
            ChannelModesChangedEvent::class => 'onChannelModesChanged',
            ChannelTopicChangedEvent::class => 'onChannelTopicChanged',
            UdbSyncRequestedEvent::class => 'onSyncRequested',
            UdbSyncCompleteEvent::class => 'onSyncComplete',
            UdbRecordReceivedEvent::class => 'onRecordReceived',
        ];
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueDelete($event->channelName);
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName($event->channelNameLower);
        if (null === $channel) {
            return;
        }

        $founder = $this->nickRepository->findById($channel->getFounderNickId());
        if (null !== $founder) {
            $this->queueInsert(sprintf('%s::founder', $event->channelName), $founder->getNickname());
        }

        if (null !== $channel->getTopic()) {
            $this->queueInsert(sprintf('%s::topic', $event->channelName), $channel->getTopic());
        }

        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null !== $view) {
            $support = $this->modeSupportProvider->getSupport();
            $formatted = $this->modesFormatter->format($view->modes, $view->modeParams, $support);
            if (null !== $formatted) {
                $this->queueInsert(sprintf('%s::modes', $event->channelName), $formatted);
            }
        }

        if ($channel->isMlockActive()) {
            $this->queueInsert(sprintf('%s::mlock', $event->channelName), '*1');
        }

        if ($channel->isTopicLock()) {
            $this->queueInsert(sprintf('%s::topiclock', $event->channelName), '*1');
        }
    }

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $founder = $this->nickRepository->findById($event->newFounderNickId);
        if (null !== $founder) {
            $this->queueInsert(sprintf('%s::founder', $event->channelName), $founder->getNickname());
        }
    }

    public function onChannelForbidden(ChannelForbiddenEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueInsert(sprintf('%s::forbid', $event->channelName), $event->reason);
    }

    public function onChannelUnforbidden(ChannelUnforbiddenEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueDelete(sprintf('%s::forbid', $event->channelName));
    }

    public function onChannelSuspended(ChannelSuspendedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueInsert(sprintf('%s::suspended', $event->channelName), '1');
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueDelete(sprintf('%s::suspended', $event->channelName));
    }

    public function onChannelAccessChanged(ChannelAccessChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        if ('DEL' === $event->action) {
            $this->queueDelete(sprintf('%s::access::%s', $event->channelName, $event->targetNickname));
        } else {
            $this->queueInsert(sprintf('%s::access::%s', $event->channelName, $event->targetNickname), (string) ($event->level ?? 0));
        }
    }

    public function onChannelMlockUpdated(ChannelMlockUpdatedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        if ($channel->isMlockActive()) {
            $this->queueInsert(sprintf('%s::mlock', $channel->getName()), '*1');
        } else {
            $this->queueDelete(sprintf('%s::mlock', $channel->getName()));
        }
    }

    public function onChannelTopiclockUpdated(ChannelTopiclockUpdatedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        if ($channel->isTopicLock()) {
            $this->queueInsert(sprintf('%s::topiclock', $channel->getName()), '*1');
        } else {
            $this->queueDelete(sprintf('%s::topiclock', $channel->getName()));
        }
    }

    public function onChannelModesChanged(ChannelModesChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $channelName = $event->channel->name->value;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel || $channel->isForbidden()) {
            return;
        }

        $support = $this->modeSupportProvider->getSupport();
        $formatted = $this->modesFormatter->format(
            $event->channel->getModes(),
            $event->channel->getModeParams(),
            $support,
        );

        if (null !== $formatted) {
            $this->queueInsert(sprintf('%s::modes', $channelName), $formatted);
        } else {
            $this->queueDelete(sprintf('%s::modes', $channelName));
        }
    }

    public function onChannelTopicChanged(ChannelTopicChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $channelName = $event->channel->name->value;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel || $channel->isForbidden()) {
            return;
        }

        $topic = $event->channel->getTopic();
        if (null !== $topic && '' !== $topic) {
            $this->queueInsert(sprintf('%s::topic', $channelName), $topic);
        } else {
            $this->queueDelete(sprintf('%s::topic', $channelName));
        }
    }

    public function onSyncRequested(UdbSyncRequestedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        if (self::BLOCK === $event->block) {
            $this->syncing = true;
            $this->receivedKeys = [];
            $this->deletedRoots = [];
            $this->pendingActions = [];

            // Request UDB's current C-block records so we can reconcile after the sync ends.
            $this->recordWriter->requestSync(self::BLOCK, $event->sourceSid);
        }
    }

    /**
     * Called when UDB finishes the block snapshot (END for staged transfers,
     * FDR for the legacy path). Missing records are queued and every pending
     * reconciliation action is flushed now that the staged session is closed.
     * Services' database is the source of truth: records present in UDB but
     * absent from services are deleted (queued by onRecordReceived) here.
     */
    public function onSyncComplete(UdbSyncCompleteEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        if (self::BLOCK !== $event->block || !$this->syncing) {
            return;
        }

        foreach ($this->canonicalRecords() as $key => $value) {
            if (!isset($this->receivedKeys[$this->normalizeKey(sprintf('%s::%s', self::BLOCK, $key))])) {
                $this->pendingActions[$key] = $value;
            }
        }

        $this->flushPendingActions();
        $this->resetSyncState();
    }

    public function onRecordReceived(UdbRecordReceivedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        // Example key: C::#canal::founder
        $parts = explode('::', $event->key);
        if (count($parts) < 3 || self::BLOCK !== $parts[0]) {
            return;
        }

        // Reconciliation runs only inside the sync window; real-time frames
        // forwarded by UDB are authoritative and are not fought back.
        if (!$this->syncing) {
            return;
        }

        $channelName = $parts[1];
        $property = $parts[2];

        $this->receivedKeys[$this->normalizeKey($event->key)] = true;

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            $root = sprintf('%s::%s', self::BLOCK, $channelName);
            $normalizedRoot = $this->normalizeKey($root);
            if (!isset($this->deletedRoots[$normalizedRoot])) {
                $this->deletedRoots[$normalizedRoot] = true;
                $this->queueDelete($channelName);
            }

            return;
        }

        if ($channel->isForbidden() && 'forbid' !== $property) {
            $this->queueDelete(substr($event->key, strlen(self::BLOCK) + 2));

            return;
        }

        if ('founder' === $property) {
            $founder = $this->nickRepository->findById($channel->getFounderNickId());
            if (null === $founder) {
                $this->queueDelete(sprintf('%s::founder', $channelName));
            } elseif (0 !== strcasecmp($founder->getNickname(), $event->value)) {
                $this->queueInsert(sprintf('%s::founder', $channelName), $founder->getNickname());
            }
        } elseif ('topic' === $property) {
            if (null === $channel->getTopic()) {
                $this->queueDelete(sprintf('%s::topic', $channelName));
            } elseif ($channel->getTopic() !== $event->value) {
                $this->queueInsert(sprintf('%s::topic', $channelName), $channel->getTopic());
            }
        } elseif ('mlock' === $property) {
            if (!$channel->isMlockActive()) {
                $this->queueDelete(sprintf('%s::mlock', $channelName));
            } elseif ('*1' !== $event->value) {
                $this->queueInsert(sprintf('%s::mlock', $channelName), '*1');
            }
        } elseif ('topiclock' === $property) {
            if (!$channel->isTopicLock()) {
                $this->queueDelete(sprintf('%s::topiclock', $channelName));
            } elseif ('*1' !== $event->value) {
                $this->queueInsert(sprintf('%s::topiclock', $channelName), '*1');
            }
        } elseif ('modes' === $property) {
            $view = $this->channelLookup->findByChannelName($channelName);
            $support = $this->modeSupportProvider->getSupport();
            $formatted = null !== $view ? $this->modesFormatter->format($view->modes, $view->modeParams, $support) : null;
            if (null === $formatted) {
                $this->queueDelete(sprintf('%s::modes', $channelName));
            } elseif ($formatted !== $event->value) {
                $this->queueInsert(sprintf('%s::modes', $channelName), $formatted);
            }
        } elseif ('access' === $property) {
            $targetNick = $parts[3] ?? '';
            if ('' === $targetNick) {
                $this->queueDelete(substr($event->key, strlen(self::BLOCK) + 2));

                return;
            }

            $targetAccount = $this->nickRepository->findByNick($targetNick);
            $access = null !== $targetAccount ? $this->accessRepository->findByChannelAndNick($channel->getId(), $targetAccount->getId()) : null;
            if (null === $access) {
                $this->queueDelete(substr($event->key, strlen(self::BLOCK) + 2));
            } elseif ((string) $access->getLevel() !== $event->value) {
                $this->queueInsert(sprintf('%s::access::%s', $channelName, $targetNick), (string) $access->getLevel());
            }
        } elseif ('forbid' === $property) {
            if (!$channel->isForbidden()) {
                $this->queueDelete(sprintf('%s::forbid', $channelName));
            } elseif ($channel->getForbiddenReason() !== $event->value) {
                $this->queueInsert(sprintf('%s::forbid', $channelName), $channel->getForbiddenReason() ?? '');
            }
        } elseif ('suspended' === $property) {
            if (!$channel->isSuspended()) {
                $this->queueDelete(sprintf('%s::suspended', $channelName));
            }
        } else {
            // pass, options, or any obsolete/unknown property
            $this->queueDelete(substr($event->key, strlen(self::BLOCK) + 2));
        }
    }

    /** @return array<string, string> */
    private function canonicalRecords(): array
    {
        $records = [];
        foreach ($this->channelRepository->listAll() as $channel) {
            if ($channel->isForbidden()) {
                $records[sprintf('%s::forbid', $channel->getName())] = $channel->getForbiddenReason() ?? '';
                continue;
            }

            if ($channel->isSuspended()) {
                $records[sprintf('%s::suspended', $channel->getName())] = '1';
            }

            $founder = $this->nickRepository->findById($channel->getFounderNickId());
            if (null !== $founder) {
                $records[sprintf('%s::founder', $channel->getName())] = $founder->getNickname();
            }

            if (null !== $channel->getTopic()) {
                $records[sprintf('%s::topic', $channel->getName())] = $channel->getTopic();
            }

            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null !== $view) {
                $support = $this->modeSupportProvider->getSupport();
                $formattedModes = $this->modesFormatter->format($view->modes, $view->modeParams, $support);
                if (null !== $formattedModes) {
                    $records[sprintf('%s::modes', $channel->getName())] = $formattedModes;
                }
            }

            if ($channel->isMlockActive()) {
                $records[sprintf('%s::mlock', $channel->getName())] = '*1';
            }

            if ($channel->isTopicLock()) {
                $records[sprintf('%s::topiclock', $channel->getName())] = '*1';
            }

            foreach ($this->accessRepository->listByChannel($channel->getId()) as $access) {
                $targetNick = $this->nickRepository->findById($access->getNickId());
                if (null !== $targetNick) {
                    $records[sprintf('%s::access::%s', $channel->getName(), $targetNick->getNickname())] = (string) $access->getLevel();
                }
            }
        }

        return $records;
    }

    private function resetSyncState(): void
    {
        $this->syncing = false;
        $this->receivedKeys = [];
        $this->deletedRoots = [];
        $this->pendingActions = [];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower($key);
    }

    private function isUdbActive(): bool
    {
        $module = $this->connectionHolder->getProtocolModule();

        return null !== $module && 'unrealudb' === $module->getProtocolName();
    }

    private function queueInsert(string $path, string $value): void
    {
        if ($this->syncing) {
            $this->pendingActions[$path] = $value;

            return;
        }

        $this->recordWriter->insert(self::BLOCK, $path, $value);
    }

    private function queueDelete(string $path): void
    {
        if ($this->syncing) {
            $this->pendingActions[$path] = false;

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }

    private function flushPendingActions(): void
    {
        foreach ($this->pendingActions as $path => $value) {
            if (false === $value) {
                $this->recordWriter->delete(self::BLOCK, $path);
            }
        }

        foreach ($this->pendingActions as $path => $value) {
            if (false === $value || $this->hasPendingRootDelete($path)) {
                continue;
            }

            $this->recordWriter->insert(self::BLOCK, $path, $value);
        }
    }

    private function hasPendingRootDelete(string $path): bool
    {
        $pos = strpos($path, '::');
        $root = false === $pos ? $path : substr($path, 0, $pos);

        return isset($this->pendingActions[$root]) && false === $this->pendingActions[$root];
    }
}
