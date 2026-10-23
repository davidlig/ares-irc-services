<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;

use function strtolower;

/**
 * Administrative channel registration on behalf of a third party.
 *
 * The channel does not need to exist on the network and the operator does not need to join it:
 * the founder is an already active registered account, which owns the channel immediately.
 * Default levels are installed exactly as in a regular registration; no throttle, rank or
 * per-account channel limit applies, because this path exists for migrations.
 */
final readonly class SaregisterChannelHandler implements SaregisterChannelHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private ChannelAkickRepositoryInterface $akickRepository,
        private ChanUserAccountPort $accounts,
        private EventBusInterface $events,
    ) {}

    public function handle(SaregisterChannel $command): SaregisterChannelResult
    {
        $channelNameLower = strtolower($command->channelName);

        if ($this->channelRepository->existsByChannelName($channelNameLower)) {
            $existing = $this->channelRepository->findByChannelName($channelNameLower);
            if (null !== $existing && $existing->isPendingDeletion()) {
                return SaregisterChannelResult::pendingDeletion($command->channelName, $command->founderNickname);
            }

            return SaregisterChannelResult::alreadyRegistered($command->channelName, $command->founderNickname);
        }

        $founder = $this->accounts->findAccountByNick($command->founderNickname);
        if (null === $founder) {
            return SaregisterChannelResult::founderNotFound($command->channelName, $command->founderNickname);
        }

        if (!$founder->registered || $founder->suspended) {
            return SaregisterChannelResult::founderInactive($command->channelName, $command->founderNickname);
        }

        $channel = RegisteredChannel::register(
            $command->occurredAt,
            $command->channelName,
            $founder->id,
            $command->description,
        );
        $this->channelRepository->save($channel);

        $this->levelRepository->removeAllForChannel($channel->getId());
        $this->akickRepository->deleteByChannelId($channel->getId());

        foreach (ChannelLevel::DEFAULTS as $key => $value) {
            $this->levelRepository->save(new ChannelLevel($channel->getId(), $key, $value));
        }

        $this->events->dispatch(new ChannelRegisteredEvent(
            $channel->getId(),
            $command->channelName,
            $channelNameLower,
            $command->occurredAt,
        ));

        return SaregisterChannelResult::registered($command->channelName, $command->founderNickname);
    }
}
