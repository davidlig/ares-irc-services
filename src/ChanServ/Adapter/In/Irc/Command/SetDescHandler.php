<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function trim;

final readonly class SetDescHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.desc.syntax')]);

            return;
        }
        $channel->updateDescription($trimmed);
        $this->channelRepository->save($channel);
        $context->reply('set.desc.updated');
    }
}
