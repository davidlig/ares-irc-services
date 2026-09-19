<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IrcNickProtectionNotifier implements NickProtectionNotifier
{
    public function __construct(
        private NickServNotifierInterface $notifier,
        private TranslatorInterface $translator,
    ) {}

    public function notifyForbidden(string $uid, string $nickname, string $reason, string $language): void
    {
        $message = $this->translator->trans(
            'protection.nick_forbidden',
            ['%nickname%' => $nickname, '%reason%' => $reason],
            'nickserv',
            $language,
        );
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
        $this->notifier->sendMessage($uid, $this->translator->trans(
            'protection.nick_in_use',
            ['%nickname%' => $nickname, '%bot%' => $botName],
            'nickserv',
            $language,
        ), $messageType);
        $this->notifier->sendMessage($uid, $this->translator->trans(
            'protection.renamed_to',
            ['%guest_nick%' => $guestNickname, '%nickname%' => $nickname, '%bot%' => $botName],
            'nickserv',
            $language,
        ), $messageType);
    }
}
