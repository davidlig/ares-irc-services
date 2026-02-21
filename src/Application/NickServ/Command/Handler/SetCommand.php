<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * SET <option> <value>
 *
 * Allows a registered and identified user to change their NickServ settings.
 *
 * Supported options:
 *   SET PASSWORD <new_password>
 *   SET EMAIL    <new_email>
 *   SET LANGUAGE <code>       (en | es | …)
 *   SET PRIVATE  ON|OFF
 */
final class SetCommand implements NickServCommandInterface
{
    private const SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'PRIVATE'];

    private const SUPPORTED_LANGUAGES = ['en', 'es'];

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'SET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'set.syntax';
    }

    public function getHelpKey(): string
    {
        return 'set.help';
    }

    public function getShortDescKey(): string
    {
        return 'set.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'PASSWORD', 'desc_key' => 'set.password.short'],
            ['name' => 'EMAIL',    'desc_key' => 'set.email.short'],
            ['name' => 'LANGUAGE', 'desc_key' => 'set.language.short'],
            ['name' => 'PRIVATE',  'desc_key' => 'set.private.short'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if ($sender === null) {
            return;
        }

        // Must be identified to use SET
        $account = $context->senderAccount;
        if ($account === null) {
            $context->reply('error.not_identified');
            return;
        }

        // Owner check: SET is only allowed for the nick you are currently using
        if (strcasecmp($sender->getNick()->value, $account->getNickname()) !== 0) {
            $context->reply('error.not_identified');
            return;
        }

        $option = strtoupper($context->args[0]);
        $value  = implode(' ', array_slice($context->args, 1));

        match ($option) {
            'PASSWORD' => $this->handlePassword($context, $account->getNickname(), $value),
            'EMAIL'    => $this->handleEmail($context, $account->getNickname(), $value),
            'LANGUAGE' => $this->handleLanguage($context, $account->getNickname(), $value),
            'PRIVATE'  => $this->handlePrivate($context, $account->getNickname(), $value),
            default    => $context->reply('set.unknown_option', [
                'option'  => $option,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]),
        };
    }

    private function handlePassword(NickServContext $context, string $nick, string $value): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.password.syntax')]);
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        $account?->changePassword(password_hash($value, PASSWORD_ARGON2ID));
        $this->nickRepository->save($account);

        $context->reply('set.password.success');
    }

    private function handleEmail(NickServContext $context, string $nick, string $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        $account?->changeEmail($value);
        $this->nickRepository->save($account);

        $context->reply('set.email.success', ['email' => $value]);
    }

    private function handleLanguage(NickServContext $context, string $nick, string $value): void
    {
        $lang = strtolower($value);

        if (!in_array($lang, self::SUPPORTED_LANGUAGES, true)) {
            $context->reply('set.language.invalid', [
                'languages' => implode(', ', self::SUPPORTED_LANGUAGES),
            ]);
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        $account?->changeLanguage($lang);
        $this->nickRepository->save($account);

        // Reply in the NEW language so the user sees confirmation in their chosen language
        $context->reply('set.language.success', ['language' => $lang]);
    }

    private function handlePrivate(NickServContext $context, string $nick, string $value): void
    {
        $flag = strtoupper($value);

        if (!in_array($flag, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.private.syntax')]);
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        $account?->setPrivate('ON' === $flag);
        $this->nickRepository->save($account);

        $context->reply('ON' === $flag ? 'set.private.on' : 'set.private.off');
    }
}
