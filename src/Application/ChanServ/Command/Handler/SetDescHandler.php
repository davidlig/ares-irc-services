<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final readonly class SetDescHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

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
