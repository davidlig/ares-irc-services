<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UnbanUser;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;

use function strtolower;

final readonly class UnbanUserHandler implements UnbanUserHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanServAccessHelper $access,
    ) {}

    public function handle(UnbanUser $command): UnbanUserResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($command->channelName);
        }
        if (!$command->founderEquivalent) {
            $this->access->requireLevel($channel, $command->accountId, ChannelLevel::KEY_UNBAN, $command->channelName, 'UNBAN');
        }

        return new UnbanUserResult($channel->getCreatedAt()->getTimestamp());
    }
}
