<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\ChannelName;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function array_values;
use function count;

class InMemoryChannelRepository implements ChannelRepositoryInterface, EventSubscriberInterface
{
    /** @var array<string, Channel> keyed by lowercase channel name */
    private array $channels = [];

    public function __construct(private CaseMapping $caseMapping = CaseMapping::Ascii) {}

    public static function getSubscribedEvents(): array
    {
        return [CaseMappingChangedEvent::class => 'onCaseMappingChanged'];
    }

    public function onCaseMappingChanged(CaseMappingChangedEvent $event): void
    {
        $this->caseMapping = $event->mapping;
        $channels = $this->channels;
        $this->channels = [];
        foreach ($channels as $channel) {
            $this->save($channel);
        }
    }

    public function save(Channel $channel): void
    {
        $channel->useCaseMapping($this->caseMapping);
        $this->channels[$this->caseMapping->fold($channel->name->value)] = $channel;
    }

    public function remove(ChannelName $name): void
    {
        unset($this->channels[$this->caseMapping->fold($name->value)]);
    }

    public function findByName(ChannelName $name): ?Channel
    {
        return $this->channels[$this->caseMapping->fold($name->value)] ?? null;
    }

    public function all(): array
    {
        return array_values($this->channels);
    }

    public function iterateAll(): iterable
    {
        foreach ($this->channels as $channel) {
            yield $channel;
        }
    }

    public function count(): int
    {
        return count($this->channels);
    }
}
