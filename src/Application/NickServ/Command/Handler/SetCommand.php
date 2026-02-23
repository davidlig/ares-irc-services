<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_slice;
use function in_array;
use function strlen;

use const FILTER_VALIDATE_EMAIL;
use const PASSWORD_ARGON2ID;

/**
 * SET <option> <value>.
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
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'PRIVATE', 'VHOST'];

    /** Max length for vhost (IRCD/DB limit). Allowed: hostname-like (letters, digits, hyphens, dots). */
    private const int VHOST_MAX_LENGTH = 255;

    private const string VHOST_PATTERN = '/^[a-zA-Z0-9.\-]+$/';

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly PendingEmailChangeRegistry $pendingEmailChangeRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
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
                'name' => 'PASSWORD',
                'desc_key' => 'set.password.short',
                'help_key' => 'set.password.help',
                'syntax_key' => 'set.password.syntax',
            ],
            [
                'name' => 'EMAIL',
                'desc_key' => 'set.email.short',
                'help_key' => 'set.email.help',
                'syntax_key' => 'set.email.syntax',
            ],
            [
                'name' => 'LANGUAGE',
                'desc_key' => 'set.language.short',
                'help_key' => 'set.language.help',
                'syntax_key' => 'set.language.syntax',
            ],
            [
                'name' => 'PRIVATE',
                'desc_key' => 'set.private.short',
                'help_key' => 'set.private.help',
                'syntax_key' => 'set.private.syntax',
            ],
            [
                'name' => 'VHOST',
                'desc_key' => 'set.vhost.short',
                'help_key' => 'set.vhost.help',
                'syntax_key' => 'set.vhost.syntax',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::IDENTIFIED_OWNER;
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return;
        }

        $option = strtoupper($context->args[0]);
        $value = implode(' ', array_slice($context->args, 1));

        match ($option) {
            'PASSWORD' => $this->handlePassword($context, $account->getNickname(), $value),
            'EMAIL' => $this->handleEmail($context, $account->getNickname(), $value),
            'LANGUAGE' => $this->handleLanguage($context, $account->getNickname(), $value),
            'PRIVATE' => $this->handlePrivate($context, $account->getNickname(), $value),
            'VHOST' => $this->handleVhost($context, $account->getNickname(), $value),
            default => $context->reply('set.unknown_option', [
                'option' => $option,
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
        $parts = explode(' ', $value, 2);
        $newEmail = trim($parts[0] ?? '');
        $token = isset($parts[1]) ? trim($parts[1]) : null;

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
            $this->messageBus->dispatch(new SendEmail($currentEmail, $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('NickServ SET EMAIL: failed to dispatch token email', [
                'nick' => $nick,
                'recipient' => $currentEmail,
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return;
        }

        $context->reply('set.email.pending_sent', [
            'current_email' => $currentEmail,
            'new_email' => $newEmail,
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
        } catch (InvalidArgumentException) {
            $context->reply('set.language.invalid', [
                'languages' => implode(', ', RegisteredNick::SUPPORTED_LANGUAGES),
            ]);

            return;
        }

        $this->nickRepository->save($account);

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

    private function handleVhost(NickServContext $context, string $nick, string $value): void
    {
        $normalized = trim($value);
        $clearKeywords = ['OFF', ''];
        if ('' === $normalized || in_array(strtoupper($normalized), $clearKeywords, true)) {
            $account = $this->nickRepository->findByNick($nick);
            if (null !== $account) {
                $account->changeVhost(null);
                $this->nickRepository->save($account);
                $context->getNotifier()->setUserVhost($context->sender->uid->value, '');
            }
            $context->reply('set.vhost.cleared');

            return;
        }

        if (strlen($normalized) > self::VHOST_MAX_LENGTH || 1 !== preg_match(self::VHOST_PATTERN, $normalized)) {
            $context->reply('set.vhost.invalid');

            return;
        }

        $account = $this->nickRepository->findByNick($nick);
        if (null === $account) {
            return;
        }

        $account->changeVhost($normalized);
        $this->nickRepository->save($account);
        $context->getNotifier()->setUserVhost($context->sender->uid->value, $normalized);
        $context->reply('set.vhost.success', ['vhost' => $normalized]);
    }
}
