<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Renders sensitive recovery material only for its intended private recipient. */
final readonly class RescueTokenPresentation
{
    public static function render(TranslatorInterface $translator, IrcRenderer $renderer, string $key, string $locale, string $token, string $bot, ?Role $role): string
    {
        $template = $translator->trans($key, [], 'nickserv', $locale);
        $parameters = [
            '%bot%' => $bot,
            '%token_display%' => new Fragment($token, Component::Command),
            '%verify%' => new Fragment('VERIFY %token%', Component::Command, ['%token%' => $token]),
        ];
        if ('rescue.issued' === $key) {
            $parameters['%warning%'] = new Fragment($translator->trans('rescue.issued_warning', [], 'nickserv', $locale), Component::ExpirationNotice);
        }

        return null === $role ? $renderer->component(Component::Text, $template, $parameters) : $renderer->reply($role, $template, $parameters);
    }
}
