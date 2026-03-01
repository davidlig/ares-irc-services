<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Set;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use const FILTER_VALIDATE_EMAIL;

final readonly class SetEmailHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function handle(\App\Application\ChanServ\Command\ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $value = trim($value);
        if ('' !== $value && false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $context->reply('set.email.invalid');

            return;
        }
        $email = '' === $value ? null : $value;
        $channel->setEmail($email);
        $this->channelRepository->save($channel);
        $context->reply(null !== $email ? 'set.email.updated' : 'set.email.cleared');
    }
}
