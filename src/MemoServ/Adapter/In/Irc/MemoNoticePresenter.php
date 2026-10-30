<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MemoNoticePresenter
{
    public function __construct(private TranslatorInterface $translator, private IrcRenderer $renderer) {}

    public function nickReceived(string $sender, string $bot, string $language): string
    {
        return $this->renderer->reply(Role::Information, $this->translator->trans('notify.nick_received', [], 'memoserv', $language), [
            '%sender%' => new Fragment($sender, Component::Emphasis),
            '%bot%' => $bot,
            '%list%' => new Fragment('LIST', Component::Command),
        ]);
    }

    public function channelReceived(string $sender, string $channel, string $bot, string $language): string
    {
        return $this->renderer->reply(Role::Information, $this->translator->trans('notify.channel_received', [], 'memoserv', $language), [
            '%sender%' => new Fragment($sender, Component::Emphasis),
            '%channel%' => new Fragment($channel, Component::Emphasis),
            '%bot%' => $bot,
            '%list%' => new Fragment('LIST ' . $channel, Component::Command),
        ]);
    }

    public function nick(int $count, string $bot, string $language): string
    {
        return $this->renderer->reply(Role::Information, $this->translator->trans('notify.nick_pending', [], 'memoserv', $language), [
            '%count%' => new Fragment((string) $count, Component::Emphasis),
            '%bot%' => $bot,
            '%list%' => new Fragment('LIST', Component::Command),
        ]);
    }

    public function channel(int $count, string $channel, string $bot, string $language): string
    {
        return $this->renderer->reply(Role::Information, $this->translator->trans('notify.channel_pending', [], 'memoserv', $language), [
            '%channel%' => new Fragment($channel, Component::Emphasis),
            '%count%' => new Fragment((string) $count, Component::Emphasis),
            '%bot%' => $bot,
            '%list%' => new Fragment('LIST ' . $channel, Component::Command),
        ]);
    }
}
