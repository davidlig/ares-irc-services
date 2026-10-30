<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\RescueTokenPresentation;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\RescueTokenNotifier;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IrcRescueTokenNotifier implements RescueTokenNotifier
{
    public function __construct(private NickServNotifierInterface $notifier, private TranslatorInterface $translator, private ?IrcRenderer $renderer = null) {}

    public function issued(string $uid, string $token, string $language, UserMessagePreference $preference): void
    {
        $renderer = $this->renderer;
        if (null === $renderer && $this->translator instanceof TranslatorBagInterface) {
            $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        }
        $message = null === $renderer
            ? $this->translator->trans('rescue.issued', ['%token%' => $token, '%bot%' => $this->notifier->getNick()], 'nickserv', $language)
            : RescueTokenPresentation::render($this->translator, $renderer, 'rescue.issued', $language, $token, $this->notifier->getNick(), null);
        $this->notifier->sendMessage($uid, $message, UserMessagePreference::PrivateMessage === $preference ? 'PRIVMSG' : 'NOTICE');
    }
}
