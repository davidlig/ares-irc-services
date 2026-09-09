<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function strtoupper;
use function trim;

final readonly class SetTopiclockHandler implements SetOptionHandlerInterface
{
    use BuildsUpdateChannelSettingRequest;

    public function __construct(private UpdateChannelSettingHandlerInterface $handler) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.topiclock.syntax')]);

            return;
        }
        $request = $this->settingRequest($context, $channel, ChannelSetting::TopicLock, $normalized);
        if (null === $request) {
            return;
        }
        $this->handler->handle($request);
        $on = 'ON' === $normalized;
        $context->reply($on ? 'set.topiclock.on' : 'set.topiclock.off');
        $nick = $context->getSenderNickname() ?? '';
        if ('' !== $nick) {
            $key = $on ? 'set.topiclock.notice_on' : 'set.topiclock.notice_off';
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $context->trans($key, ['%nickname%' => $nick]));
        }
    }
}
