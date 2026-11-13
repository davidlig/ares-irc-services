<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Domain\ValueObject\CaseMapping;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function microtime;

/**
 * In-memory registry of channel names that have completed sync
 * (all ChannelSyncedEvent subscribers have run: +r, SECURE strip, MLOCK, topic apply).
 */
final class ChannelSyncCompletedRegistry implements ChannelSyncCompletedRegistryInterface, EventSubscriberInterface
{
    /** @var array<string, float> lowercase channel name => Unix timestamp */
    private array $completedAt = [];

    /** @var array<string, string> */
    private array $names = [];

    public function __construct(private CaseMapping $caseMapping = CaseMapping::Ascii) {}

    public static function getSubscribedEvents(): array
    {
        return [CaseMappingChangedEvent::class => 'onCaseMappingChanged'];
    }

    public function onCaseMappingChanged(CaseMappingChangedEvent $event): void
    {
        $this->caseMapping = $event->mapping;
        $times = $this->completedAt;
        $names = $this->names;
        $this->completedAt = [];
        $this->names = [];
        foreach ($names as $oldKey => $name) {
            $key = $event->mapping->fold($name);
            $this->names[$key] = $name;
            $this->completedAt[$key] = $times[$oldKey];
        }
    }

    public function markSyncCompleted(string $channelName): void
    {
        $key = $this->caseMapping->fold($channelName);
        $this->names[$key] = $channelName;
        $this->completedAt[$key] = microtime(true);
    }

    public function remove(string $channelName): void
    {
        unset($this->completedAt[$this->caseMapping->fold($channelName)], $this->names[$this->caseMapping->fold($channelName)]);
    }

    public function isSyncCompleted(string $channelName): bool
    {
        return isset($this->completedAt[$this->caseMapping->fold($channelName)]);
    }

    public function getSyncCompletedAt(string $channelName): ?float
    {
        $key = $this->caseMapping->fold($channelName);

        return $this->completedAt[$key] ?? null;
    }
}
