<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function ceil;
use function implode;
use function in_array;
use function is_string;
use function strtolower;

final class SetNickSettingPresentation
{
    public static function present(NickServContext $context, SetNickSettingResult $result): bool
    {
        return match ($result->outcome) {
            SetNickSettingOutcome::Changed => self::presentChanged($context, $result),
            SetNickSettingOutcome::SessionLanguageChanged => self::reply($context, 'set.language.success', ['language' => $result->value]),
            SetNickSettingOutcome::EmailConfirmationSent => self::presentEmailConfirmation($context, $result),
            SetNickSettingOutcome::TargetNotRegistered, SetNickSettingOutcome::NoCurrentEmail => self::reject($context, 'error.not_identified'),
            SetNickSettingOutcome::TargetIsRoot,
            SetNickSettingOutcome::TargetIsIrcop,
            SetNickSettingOutcome::TargetIsService => self::reject($context, 'saset.cannot_modify_oper', ['nickname' => $result->targetNickname]),
            SetNickSettingOutcome::MissingValue, SetNickSettingOutcome::InvalidFlag => self::reject($context, 'error.syntax', [
                'syntax' => $context->trans('set.' . strtolower($result->option->value) . '.syntax'),
            ]),
            SetNickSettingOutcome::BlockedEmailDomain => self::reject($context, 'error.email_domain_blocked'),
            SetNickSettingOutcome::InvalidEmail => self::reject($context, 'register.invalid_email'),
            SetNickSettingOutcome::EmailAlreadyUsed => self::reject($context, 'register.email_already_used', ['email' => $result->value]),
            SetNickSettingOutcome::InvalidEmailToken => self::reject($context, 'set.email.invalid_token'),
            SetNickSettingOutcome::TokenRequired => self::reject($context, 'set.email.token_required'),
            SetNickSettingOutcome::CredentialUnavailable => self::reject($context, 'rescue.unavailable'),
            SetNickSettingOutcome::TokenThrottled => self::reject($context, 'rescue.throttled', ['minutes' => (int) ceil($result->retryAfterSeconds / 60)]),
            SetNickSettingOutcome::MailDeliveryFailed => self::reject($context, 'error.mail_failed'),
            SetNickSettingOutcome::InvalidLanguage => self::reject($context, 'set.language.invalid', [
                'languages' => implode(', ', RegisteredNick::SUPPORTED_LANGUAGES),
            ]),
            SetNickSettingOutcome::InvalidTimezone => self::reject($context, 'set.timezone.invalid', ['timezone' => $result->value]),
            SetNickSettingOutcome::ForcedVhost => self::reject($context, 'set.vhost.forced'),
            SetNickSettingOutcome::InvalidVhost => self::reject($context, 'set.vhost.invalid'),
            SetNickSettingOutcome::VhostTaken => self::reject($context, 'set.vhost.taken'),
        };
    }

    private static function presentEmailConfirmation(NickServContext $context, SetNickSettingResult $result): bool
    {
        $current = $result->currentEmail ?? '';
        $new = $result->newEmail ?? '';
        $context->reply('set.email.pending_sent', [
            'current_email' => $current,
            'new_email' => $new,
            'first' => $context->localizedFragment('set.email.pending_line_1', Component::Text, [
                'current_email' => new Fragment($current, Component::Emphasis),
            ]),
            'second' => $context->localizedFragment('set.email.pending_line_2', Component::IndentedRow, [
                'new_email' => new Fragment($new, Component::Emphasis),
            ]),
            'third' => $context->localizedFragment('set.email.pending_line_3', Component::IndentedRow, [
                'command' => $context->localizedFragment('set.email.pending_command', Component::Command, ['new_email' => $new]),
            ]),
            'fourth' => $context->localizedFragment('set.email.pending_line_4', Component::IndentedRow),
        ], Role::Information);

        return true;
    }

    private static function presentChanged(NickServContext $context, SetNickSettingResult $result): bool
    {
        return match ($result->option) {
            SetNickSettingOption::Password => self::reply($context, 'set.password.success'),
            SetNickSettingOption::Email => self::reply($context, 'set.email.success', ['email' => $result->value]),
            SetNickSettingOption::Language => self::reply($context, 'set.language.success', ['language' => $result->value]),
            SetNickSettingOption::Timezone => null === $result->value
                ? self::reply($context, 'set.timezone.cleared')
                : self::reply($context, 'set.timezone.success', ['timezone' => $result->value]),
            SetNickSettingOption::PrivateMode => self::reply($context, 'ON' === $result->value ? 'set.private.on' : 'set.private.off'),
            SetNickSettingOption::MessageMode => self::reply($context, 'ON' === $result->value ? 'set.msg.on' : 'set.msg.off'),
            SetNickSettingOption::Vhost => null === $result->value
                ? self::reply($context, 'set.vhost.cleared')
                : self::reply($context, 'set.vhost.success', ['vhost' => $result->value]),
        };
    }

    /** @param array<string, mixed> $params */
    private static function reply(NickServContext $context, string $key, array $params = []): bool
    {
        if (in_array($key, ['set.private.on', 'set.private.off', 'set.msg.on', 'set.msg.off'], true)) {
            $context->replyWithLocalizedFragment($key, $params, Role::Success, $key . '_value', Component::Emphasis);
        } else {
            $context->replyEmphasized($key, $params, Role::Success, ['email', 'language', 'timezone', 'vhost']);
        }

        return true;
    }

    /** @param array<string, mixed> $params */
    private static function reject(NickServContext $context, string $key, array $params = []): bool
    {
        if ('rescue.unavailable' === $key) {
            $context->replyRescueUnavailable();
        } elseif (in_array($key, ['set.email.token_required', 'set.email.invalid_token'], true)) {
            $context->replyWithLocalizedFragment($key, $params, Role::Error, $key . '_value', Component::Command);
        } elseif ('error.syntax' === $key && isset($params['syntax']) && is_string($params['syntax'])) {
            $context->replyCommonError($key, ['syntax' => new Fragment($params['syntax'], Component::Command)]);
        } elseif ('error.not_identified' === $key) {
            $context->replyNotIdentified();
        } elseif (in_array($key, ['error.email_domain_blocked', 'error.mail_failed'], true)) {
            $context->replyCommonError($key, $params);
        } else {
            $context->replyEmphasized($key, $params, Role::Error, ['email', 'timezone', 'minutes']);
        }

        return false;
    }
}
