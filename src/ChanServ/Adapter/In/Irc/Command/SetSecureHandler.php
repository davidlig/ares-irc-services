<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function strtoupper;
use function trim;

final readonly class SetSecureHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.secure.syntax')]);

            return;
        }
        $on = 'ON' === $normalized;
        $channel->configureSecure($on);
        $this->channelRepository->save($channel);
        if ($on) {
            $this->eventDispatcher->dispatch(new ChannelSecureEnabledEvent($channel->getName()));
        }
        $context->reply($on ? 'set.secure.on' : 'set.secure.off');

        $nick = $context->sender->nick ?? '';
        if ('' !== $nick) {
            $key = $on ? 'set.secure.notice_on' : 'set.secure.notice_off';
            $notice = $context->trans($key, ['%nickname%' => $nick]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }
}
