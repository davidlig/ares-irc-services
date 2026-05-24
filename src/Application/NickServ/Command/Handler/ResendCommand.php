<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function sprintf;

/**
 * RESEND.
 *
 * Regenerates and resends the verification token to the email address
 * provided during REGISTER. Only works while the account is in PENDING status.
 */
final readonly class ResendCommand implements NickServCommandInterface
{
    private const int TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly int $resendMinIntervalSeconds,
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

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;

        if (null === $sender) {
            return;
        }

        $account = $this->validateAndGetAccount($context, $sender->nick);

        if (null === $account) {
            return;
        }

        $this->performResend($context, $sender->nick, $account);
    }

    private function validateAndGetAccount(NickServContext $context, string $nick): ?RegisteredNick
    {
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isPending()) {
            $context->reply('resend.no_pending');

            return null;
        }

        if ($this->isResendThrottled($context, $nick)) {
            return null;
        }

        return $account;
    }

    private function performResend(NickServContext $context, string $nick, RegisteredNick $account): void
    {
        $token = SecureToken::hex(32);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));

        $context->getPendingVerificationRegistry()->store($nick, $token, $expiresAt);

        $recipientEmail = $account->getEmail() ?? '';

        if ('' !== $recipientEmail && !$this->dispatchResendEmail($context, $nick, $token, $recipientEmail)) {
            return;
        }

        $context->getPendingVerificationRegistry()->recordResend($nick);
        $context->reply('resend.success', ['email' => $recipientEmail]);
    }

    private function dispatchResendEmail(NickServContext $context, string $nick, string $token, string $recipientEmail): bool
    {
        try {
            $locale = $context->getLanguage();
            $subject = $this->translator->trans('resend_verification_subject', ['%bot%' => $context->getNotifier()->getNick()], 'mail', $locale);
            $body = $this->translator->trans('resend_verification_body', ['%nickname%' => $nick, '%token%' => $token, '%bot%' => $context->getNotifier()->getNick()], 'mail', $locale);
            $this->messageBus->dispatch(new SendEmail($recipientEmail, $subject, $body));

            return true;
        } catch (Throwable $e) {
            $this->logger->error('NickServ RESEND: failed to dispatch verification email', [
                'nick' => $nick,
                'recipient' => $recipientEmail,
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return false;
        }
    }

    private function isResendThrottled(NickServContext $context, string $nick): bool
    {
        $registry = $context->getPendingVerificationRegistry();
        $lastResendAt = $registry->getLastResendAt($nick);

        if (null !== $lastResendAt && $this->resendMinIntervalSeconds > 0) {
            $nextAllowedAt = $lastResendAt->modify(sprintf('+%d seconds', $this->resendMinIntervalSeconds));
            $now = new DateTimeImmutable();

            if ($now < $nextAllowedAt) {
                $remainingSeconds = $nextAllowedAt->getTimestamp() - $now->getTimestamp();
                $minutes = (int) ceil($remainingSeconds / 60);
                $context->reply('resend.throttled', ['%minutes%' => (string) $minutes]);

                return true;
            }
        }

        return false;
    }
}
