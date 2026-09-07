<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function filter_var;
use function trim;

use const FILTER_VALIDATE_EMAIL;

final readonly class SetEmailHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $value = trim($value);
        if ('' !== $value && false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $context->reply('set.email.invalid');

            return;
        }
        $email = '' === $value ? null : $value;
        $channel->updateEmail($email);
        $this->channelRepository->save($channel);
        $context->reply(null !== $email ? 'set.email.updated' : 'set.email.cleared');
    }
}
