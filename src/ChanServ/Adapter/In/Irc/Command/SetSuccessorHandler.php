<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingOutcome;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class SetSuccessorHandler implements SetOptionHandlerInterface
{
    use BuildsUpdateChannelSettingRequest;

    public function __construct(private UpdateChannelSettingHandlerInterface $handler) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $request = $this->settingRequest($context, $channel, ChannelSetting::Successor, $value);
        if (null === $request) {
            return;
        }

        $result = $this->handler->handle($request);
        $this->present($context, $channel, $result);
    }

    private function present(ChanServContext $context, RegisteredChannel $channel, UpdateChannelSettingResult $result): void
    {
        $nickname = $result->targetNickname ?? '';
        match ($result->outcome) {
            UpdateChannelSettingOutcome::SuccessorNotFound => $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]),
            UpdateChannelSettingOutcome::SuccessorSuspended => $context->reply('set.successor.suspended', ['%nickname%' => $nickname]),
            UpdateChannelSettingOutcome::SuccessorNotRegistered => $context->reply('set.successor.must_be_registered', ['%nickname%' => $nickname]),
            UpdateChannelSettingOutcome::SuccessorIsFounder => $context->reply('set.successor.cannot_be_founder', ['%nickname%' => $nickname]),
            UpdateChannelSettingOutcome::Cleared => $this->presentCleared($context, $channel),
            UpdateChannelSettingOutcome::Updated => $this->presentUpdated($context, $channel, $nickname),
            default => null,
        };
    }

    private function presentCleared(ChanServContext $context, RegisteredChannel $channel): void
    {
        $context->reply('set.successor.cleared');
        $sender = $context->sender;
        if (null !== $sender) {
            $notice = $context->trans('set.successor.notice_channel_cleared', ['%from%' => $sender->nick]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }

    private function presentUpdated(ChanServContext $context, RegisteredChannel $channel, string $nickname): void
    {
        $context->reply('set.successor.updated', ['%nickname%' => $nickname]);
        $sender = $context->sender;
        if (null !== $sender) {
            $notice = $context->trans('set.successor.notice_channel', [
                '%from%' => $sender->nick,
                '%nickname%' => $nickname,
            ]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }
}
