<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Set;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final readonly class SetTopiclockHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function handle(\App\Application\ChanServ\Command\ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.topiclock.syntax')]);

            return;
        }
        $on = 'ON' === $normalized;
        $channel->setTopicLock($on);
        $this->channelRepository->save($channel);
        $context->reply($on ? 'set.topiclock.on' : 'set.topiclock.off');

        $nick = $context->sender?->nick ?? '';
        if ('' !== $nick) {
            $key = $on ? 'set.topiclock.notice_on' : 'set.topiclock.notice_off';
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->trans($key, ['%nick%' => $nick]));
        }
    }
}
