<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\NickServ\Application\UseCase\Set\SetNickSettingOutcome;
use App\NickServ\Application\UseCase\Set\SetNickSettingResult;
use App\NickServ\Domain\Entity\RegisteredNick;

final class SetNickSettingPresentation
{
    public static function present(NickServContext $context, SetNickSettingResult $result): bool
    {
        return match ($result->outcome) {
            SetNickSettingOutcome::Changed => self::presentChanged($context, $result),
            SetNickSettingOutcome::SessionLanguageChanged => self::reply($context, 'set.language.success', ['language' => $result->value]),
            SetNickSettingOutcome::EmailConfirmationSent => self::reply($context, 'set.email.pending_sent', [
                'current_email' => $result->currentEmail,
                'new_email' => $result->newEmail,
            ]),
            SetNickSettingOutcome::TargetNotRegistered, SetNickSettingOutcome::NoCurrentEmail => self::reject($context, 'error.not_identified'),
            SetNickSettingOutcome::TargetIsRoot,
            SetNickSettingOutcome::TargetIsIrcop,
            SetNickSettingOutcome::TargetIsService => self::reject($context, 'saset.cannot_modify_oper', ['nickname' => $result->targetNickname]),
            SetNickSettingOutcome::MissingValue, SetNickSettingOutcome::InvalidFlag => self::reject($context, 'error.syntax', [
                'syntax' => $context->trans('set.' . strtolower($result->option->value) . '.syntax'),
            ]),
            SetNickSettingOutcome::InvalidEmail => self::reject($context, 'register.invalid_email'),
            SetNickSettingOutcome::EmailAlreadyUsed => self::reject($context, 'register.email_already_used', ['email' => $result->value]),
            SetNickSettingOutcome::InvalidEmailToken => self::reject($context, 'set.email.invalid_token'),
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
        $context->reply($key, $params);

        return true;
    }

    /** @param array<string, mixed> $params */
    private static function reject(NickServContext $context, string $key, array $params = []): bool
    {
        $context->reply($key, $params);

        return false;
    }
}
