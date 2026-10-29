<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IrcNickProtectionNotifier implements NickProtectionNotifier
{
    public function __construct(
        private NickServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private ?IrcRenderer $renderer = null,
    ) {}

    public function notifyForbidden(string $uid, string $nickname, string $reason, string $language): void
    {
        $renderer = $this->renderer();
        $message = null === $renderer
            ? $this->translator->trans('protection.nick_forbidden', ['%nickname%' => $nickname, '%reason%' => $reason], 'nickserv', $language)
            : $renderer->reply(Role::Error, $this->translator->trans('protection.nick_forbidden', [], 'nickserv', $language), [
                '%nickname%' => new Fragment($nickname, Component::Emphasis),
                '%reason%' => $reason,
            ]);
        $this->notifier->sendMessage($uid, $message, 'NOTICE');
    }

    public function notifyRename(
        string $uid,
        string $nickname,
        string $guestNickname,
        string $language,
        UserMessagePreference $preference,
    ): void {
        $messageType = UserMessagePreference::PrivateMessage === $preference ? 'PRIVMSG' : 'NOTICE';
        $botName = $this->notifier->getNick();
        $renderer = $this->renderer();
        if (null === $renderer) {
            $this->notifier->sendMessage($uid, $this->translator->trans('protection.nick_in_use', ['%nickname%' => $nickname, '%bot%' => $botName], 'nickserv', $language), $messageType);
            $this->notifier->sendMessage($uid, $this->translator->trans('protection.renamed_to', ['%guest_nick%' => $guestNickname, '%nickname%' => $nickname, '%bot%' => $botName], 'nickserv', $language), $messageType);

            return;
        }
        $this->notifier->sendMessage($uid, $renderer->reply(Role::Warning, $this->translator->trans('protection.nick_in_use', [], 'nickserv', $language), [
            '%nickname%' => new Fragment($nickname, Component::Emphasis),
            '%bot%' => $botName,
        ]), $messageType);
        $command = $this->translator->trans('protection.renamed_command', [], 'nickserv', $language);
        $this->notifier->sendMessage($uid, $renderer->reply(Role::Information, $this->translator->trans('protection.renamed_to', [], 'nickserv', $language), [
            '%guest_nick%' => new Fragment($guestNickname, Component::Emphasis),
            '%bot%' => $botName,
            '%identify%' => new Fragment($command, Component::Command, ['%nickname%' => $nickname]),
        ]), $messageType);
    }

    private function renderer(): ?IrcRenderer
    {
        if (null !== $this->renderer) {
            return $this->renderer;
        }

        return $this->translator instanceof TranslatorBagInterface
            ? new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()))
            : null;
    }
}
