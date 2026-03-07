<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use InvalidArgumentException;

final readonly class SetEntrymsgHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        try {
            $channel->updateEntrymsg($value);
        } catch (InvalidArgumentException $e) {
            $context->reply('set.entrymsg.too_long', ['%max%' => (string) RegisteredChannel::ENTRYMSG_MAX_LENGTH]);

            return;
        }
        $this->channelRepository->save($channel);
        $context->reply('set.entrymsg.updated');
    }
}
