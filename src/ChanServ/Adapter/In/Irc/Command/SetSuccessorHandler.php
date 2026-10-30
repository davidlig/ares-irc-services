<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\UpdateSetting\ChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandlerInterface;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingOutcome;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

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
            UpdateChannelSettingOutcome::SuccessorNotFound => $context->reply('presentation.error.nick_not_registered', ['%nickname%' => new Fragment((string) $nickname, Component::Emphasis)], Role::Error),
            UpdateChannelSettingOutcome::SuccessorSuspended => $context->reply('set.successor.suspended', ['%nickname%' => new Fragment($nickname, Component::Emphasis)], Role::Error),
            UpdateChannelSettingOutcome::SuccessorNotRegistered => $context->reply('set.successor.must_be_registered', ['%nickname%' => new Fragment($nickname, Component::Emphasis)], Role::Error),
            UpdateChannelSettingOutcome::SuccessorIsFounder => $context->reply('set.successor.cannot_be_founder', ['%nickname%' => new Fragment($nickname, Component::Emphasis)], Role::Error),
            UpdateChannelSettingOutcome::Cleared => $this->presentCleared($context, $channel),
            UpdateChannelSettingOutcome::Updated => $this->presentUpdated($context, $channel, $nickname),
            default => null,
        };
    }

    private function presentCleared(ChanServContext $context, RegisteredChannel $channel): void
    {
        $context->reply('set.successor.cleared', role: Role::Success);
        $sender = $context->sender;
        if (null !== $sender) {
            $notice = $context->renderReply(Role::Success, 'set.successor.notice_channel_cleared', ['%from%' => new Fragment($sender->nick, Component::Actor)]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }

    private function presentUpdated(ChanServContext $context, RegisteredChannel $channel, string $nickname): void
    {
        $context->reply('set.successor.updated', ['%nickname%' => new Fragment($nickname, Component::Emphasis)], Role::Success);
        $sender = $context->sender;
        if (null !== $sender) {
            $notice = $context->renderReply(Role::Success, 'set.successor.notice_channel', [
                '%from%' => new Fragment($sender->nick, Component::Actor),
                '%nickname%' => new Fragment($nickname, Component::Emphasis),
            ]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }
}
