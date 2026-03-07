<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final readonly class SetUrlHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $url = '' === trim($value) ? null : trim($value);
        $channel->updateUrl($url);
        $this->channelRepository->save($channel);
        $context->reply(null !== $url ? 'set.url.updated' : 'set.url.cleared');
    }
}
