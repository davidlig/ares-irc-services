<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use InvalidArgumentException;

/**
 * Core implements ChannelLookupPort: resolves a channel by name and returns
 * a ChannelView DTO for Services. No Domain\IRC entities leak to Services.
 */
final readonly class CoreChannelLookupAdapter implements ChannelLookupPort
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function findByChannelName(string $channelName): ?ChannelView
    {
        try {
            $name = new ChannelName($channelName);
        } catch (InvalidArgumentException) {
            return null;
        }

        $channel = $this->channelRepository->findByName($name);
        if (null === $channel) {
            return null;
        }

        $members = [];
        foreach ($channel->getMembers() as $member) {
            $letter = $member->role->toModeLetter();
            $members[] = [
                'uid' => $member->uid->value,
                'roleLetter' => $letter,
                'prefixLetters' => $member->prefixLetters,
            ];
        }

        return new ChannelView(
            name: $channel->name->value,
            modes: $channel->getModes(),
            topic: $channel->getTopic(),
            memberCount: $channel->getMemberCount(),
            members: $members,
            timestamp: $channel->getCreatedAt()->getTimestamp(),
            modeParams: $channel->getModeParams(),
        );
    }

    /**
     * @return ChannelView[]
     */
    public function listAll(): array
    {
        $channels = $this->channelRepository->all();
        $views = [];
        foreach ($channels as $channel) {
            $members = [];
            foreach ($channel->getMembers() as $member) {
                $letter = $member->role->toModeLetter();
                $members[] = [
                    'uid' => $member->uid->value,
                    'roleLetter' => $letter,
                    'prefixLetters' => $member->prefixLetters,
                ];
            }
            $views[] = new ChannelView(
                name: $channel->name->value,
                modes: $channel->getModes(),
                topic: $channel->getTopic(),
                memberCount: $channel->getMemberCount(),
                members: $members,
                timestamp: $channel->getCreatedAt()->getTimestamp(),
                modeParams: $channel->getModeParams(),
            );
        }

        return $views;
    }
}
