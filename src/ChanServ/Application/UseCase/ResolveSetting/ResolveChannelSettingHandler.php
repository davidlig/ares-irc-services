<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ResolveSetting;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;

use function in_array;
use function strtolower;

final readonly class ResolveChannelSettingHandler implements ResolveChannelSettingHandlerInterface
{
    private const array FOUNDER_ONLY_OPTIONS = ['FOUNDER', 'SUCCESSOR'];

    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanServAccessHelper $access,
    ) {}

    public function handle(ResolveChannelSetting $query): RegisteredChannel
    {
        $channel = $this->channels->findByChannelName(strtolower($query->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($query->channelName);
        }
        if ($query->founderEquivalent) {
            return $channel;
        }
        if (in_array($query->option, self::FOUNDER_ONLY_OPTIONS, true)) {
            if (!$channel->isFounder($query->actorAccountId)) {
                throw InsufficientAccessException::forOperation($query->channelName, 'SET ' . $query->option);
            }

            return $channel;
        }

        $this->access->requireLevel($channel, $query->actorAccountId, ChannelLevel::KEY_SET, $query->channelName, 'SET');

        return $channel;
    }
}
