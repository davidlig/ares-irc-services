<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\RescueTokenNotifier;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IrcRescueTokenNotifier implements RescueTokenNotifier
{
    public function __construct(private NickServNotifierInterface $notifier, private TranslatorInterface $translator) {}

    public function issued(string $uid, string $token, string $language, UserMessagePreference $preference): void
    {
        $message = $this->translator->trans('rescue.issued', ['%token%' => $token, '%bot%' => $this->notifier->getNick()], 'nickserv', $language);
        $this->notifier->sendMessage($uid, $message, UserMessagePreference::PrivateMessage === $preference ? 'PRIVMSG' : 'NOTICE');
    }
}
