<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function strtoupper;
use function trim;

final readonly class SetTopiclockHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.topiclock.syntax')]);

            return;
        }
        $on = 'ON' === $normalized;
        $channel->configureTopicLock($on);
        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelTopiclockUpdatedEvent($channel->getName()));
        $context->reply($on ? 'set.topiclock.on' : 'set.topiclock.off');

        $nick = $context->sender->nick ?? '';
        if ('' !== $nick) {
            $key = $on ? 'set.topiclock.notice_on' : 'set.topiclock.notice_off';
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->trans($key, ['%nickname%' => $nick]));
        }
    }
}
