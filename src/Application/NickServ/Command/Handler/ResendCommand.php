<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Mail\MailerInterface;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * RESEND
 *
 * Regenerates and resends the verification token to the email address
 * provided during REGISTER. Only works while the account is in PENDING status.
 */
final readonly class ResendCommand implements NickServCommandInterface
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getName(): string
    {
        return 'RESEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'resend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'resend.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'resend.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
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

        $nick    = $sender->getNick()->value;
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isPending()) {
            $context->reply('resend.no_pending');
            return;
        }

        $token     = bin2hex(random_bytes(16));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));

        $context->getPendingVerificationRegistry()->store($nick, $token, $expiresAt);

        $recipientEmail = $account->getEmail() ?? '';
        if ('' !== $recipientEmail) {
            try {
                $locale = $context->getLanguage();
                $subject = $this->translator->trans('resend_verification_subject', [], 'mail', $locale);
                $body = $this->translator->trans('resend_verification_body', ['%nickname%' => $nick, '%token%' => $token], 'mail', $locale);
                $this->mailer->send($recipientEmail, $subject, $body);
            } catch (\Throwable) {
                $context->reply('error.mail_failed');
                return;
            }
        }

        $context->reply('resend.success', ['email' => $recipientEmail]);
    }
}
