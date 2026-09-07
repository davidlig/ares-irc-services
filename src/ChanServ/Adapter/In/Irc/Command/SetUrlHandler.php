<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function trim;

final readonly class SetUrlHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $url = '' === trim($value) ? null : trim($value);
        $channel->updateUrl($url);
        $this->channelRepository->save($channel);
        $context->reply(null !== $url ? 'set.url.updated' : 'set.url.cleared');
    }
}
