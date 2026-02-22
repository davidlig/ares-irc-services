<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Mail\MailerInterface;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SET <option> <value>
 *
 * Allows a registered and identified user to change their NickServ settings.
 *
 * Supported options:
 *   SET PASSWORD <new_password>
 *   SET EMAIL    <new_email>              — request change (token sent to current email)
 *   SET EMAIL    <new_email> <token>      — confirm change with token
 *   SET LANGUAGE <code>       (en | es | …)
 *   SET PRIVATE  ON|OFF
 */
final readonly class SetCommand implements NickServCommandInterface
{
    private const SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'PRIVATE'];

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly PendingEmailChangeRegistry $pendingEmailChangeRegistry,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
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
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'set.syntax';
    }

    public function getHelpKey(): string
    {
        return 'set.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'set.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            [
                'name'       => 'PASSWORD',
                'desc_key'   => 'set.password.short',
                'help_key'   => 'set.password.help',
                'syntax_key' => 'set.password.syntax',
            ],
            [
                'name'       => 'EMAIL',
                'desc_key'   => 'set.email.short',
                'help_key'   => 'set.email.help',
                'syntax_key' => 'set.email.syntax',
            ],
            [
                'name'       => 'LANGUAGE',
                'desc_key'   => 'set.language.short',
                'help_key'   => 'set.language.help',
                'syntax_key' => 'set.language.syntax',
            ],
            [
                'name'       => 'PRIVATE',
                'desc_key'   => 'set.private.short',
                'help_key'   => 'set.private.help',
                'syntax_key' => 'set.private.syntax',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        // Must be identified to use SET
        $account = $context->senderAccount;
        if (null === $account) {
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
        $parts    = explode(' ', $value, 2);
        $newEmail = trim($parts[0] ?? '');
        $token    = isset($parts[1]) ? trim($parts[1]) : null;

        if ('' === $newEmail) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.email.syntax')]);
            return;
        }

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        if (null === $account || null === $account->getEmail()) {
            $context->reply('error.not_identified');
            return;
        }

        if (null !== $token && '' !== $token) {
            $this->confirmEmailChange($context, $nick, $newEmail, $token, $account);
            return;
        }

        $this->requestEmailChange($context, $nick, $newEmail, $account->getEmail());
    }

    private function requestEmailChange(NickServContext $context, string $nick, string $newEmail, string $currentEmail): void
    {
        $token = bin2hex(random_bytes(16));
        $this->pendingEmailChangeRegistry->store($nick, $newEmail, $token);

        try {
            $locale = $context->getLanguage();
            $subject = $this->translator->trans('email_change_token_subject', [], 'mail', $locale);
            $body = $this->translator->trans('email_change_token_body', ['%new_email%' => $newEmail, '%token%' => $token], 'mail', $locale);
            $this->mailer->send($currentEmail, $subject, $body);
        } catch (\Throwable) {
            $context->reply('error.mail_failed');
            return;
        }

        $context->reply('set.email.pending_sent', [
            'current_email' => $currentEmail,
            'new_email'    => $newEmail,
        ]);
    }

    private function confirmEmailChange(NickServContext $context, string $nick, string $newEmail, string $token, RegisteredNick $account): void
    {
        if (!$this->pendingEmailChangeRegistry->consume($nick, $newEmail, $token)) {
            $context->reply('set.email.invalid_token');
            return;
        }

        $account->changeEmail($newEmail);
        $this->nickRepository->save($account);

        $context->reply('set.email.success', ['email' => $newEmail]);
    }

    private function handleLanguage(NickServContext $context, string $nick, string $value): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.language.syntax')]);
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        if (null === $account) {
            return;
        }

        try {
            $account->changeLanguage($value);
        } catch (\InvalidArgumentException) {
            $context->reply('set.language.invalid', [
                'languages' => implode(', ', RegisteredNick::SUPPORTED_LANGUAGES),
            ]);
            return;
        }

        $this->nickRepository->save($account);

        // Reply in the NEW language so the user sees confirmation in their chosen language
        $context->reply('set.language.success', ['language' => $account->getLanguage()]);
    }

    private function handlePrivate(NickServContext $context, string $nick, string $value): void
    {
        $flag = strtoupper($value);

        if (!in_array($flag, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.private.syntax')]);
            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        $account?->switchPrivate('ON' === $flag);
        $this->nickRepository->save($account);

        $context->reply('ON' === $flag ? 'set.private.on' : 'set.private.off');
    }
}
