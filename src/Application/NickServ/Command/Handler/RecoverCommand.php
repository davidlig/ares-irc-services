<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Helper\EmailMasker;
use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * RECOVER <nickname> [token].
 *
 * Without token: sends a recovery token to the account's email (masked hint shown).
 * With token: validates token, sets a new random password, shows IDENTIFY and SET PASSWORD commands.
 */
final readonly class RecoverCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly int $recoverTokenTtlSeconds,
        private readonly int $recoverMinIntervalSeconds,
    ) {
    }

    public function getName(): string
    {
        return 'RECOVER';
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
        return 'recover.syntax';
    }

    public function getHelpKey(): string
    {
        return 'recover.help';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'recover.short';
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

        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        if (1 === count($context->args)) {
            $this->requestToken($context, $targetNick, $account);

            return;
        }

        $this->consumeToken($context, $targetNick, $context->args[1], $account);
    }

    private function requestToken(NickServContext $context, string $targetNick, ?RegisteredNick $account): void
    {
        if (null === $account) {
            $context->reply('recover.not_registered', ['nickname' => $targetNick]);

            return;
        }

        if ($account->isPending()) {
            $context->reply('recover.pending', ['nickname' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('recover.suspended', [
                'nickname' => $targetNick,
                'reason' => $account->getReason() ?? '',
            ]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('recover.forbidden', ['nickname' => $targetNick]);

            return;
        }

        $email = $account->getEmail();
        if (null === $email || '' === $email) {
            $context->reply('recover.no_email', ['nickname' => $targetNick]);

            return;
        }

        $registry = $context->getRecoveryTokenRegistry();
        $lastRecoverAt = $registry->getLastRecoverAt($targetNick);
        if (null !== $lastRecoverAt && $this->recoverMinIntervalSeconds > 0) {
            $nextAllowedAt = $lastRecoverAt->modify(sprintf('+%d seconds', $this->recoverMinIntervalSeconds));
            $now = new DateTimeImmutable();
            if ($now < $nextAllowedAt) {
                $remainingSeconds = $nextAllowedAt->getTimestamp() - $now->getTimestamp();
                $minutes = (int) ceil($remainingSeconds / 60);
                $context->reply('recover.throttled', ['minutes' => (string) $minutes]);

                return;
            }
        }

        $token = SecureToken::hex(32);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->recoverTokenTtlSeconds));
        $registry->store($targetNick, $token, $expiresAt);

        try {
            $locale = $account->getLanguage();
            $subject = $this->translator->trans('recovery_token_subject', [], 'mail', $locale);
            $body = $this->translator->trans('recovery_token_body', [
                '%nickname%' => $targetNick,
                '%token%' => $token,
                '%bot%' => $context->getNotifier()->getNick(),
            ], 'mail', $locale);
            $this->messageBus->dispatch(new SendEmail($email, $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('NickServ RECOVER: failed to dispatch recovery email', [
                'nick' => $targetNick,
                'recipient' => $email,
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return;
        }

        $registry->recordRecover($targetNick);
        $emailHint = EmailMasker::mask($email);
        $context->reply('recover.email_sent', ['email_hint' => $emailHint]);
    }

    private function consumeToken(NickServContext $context, string $targetNick, string $token, ?RegisteredNick $account): void
    {
        if (null === $account) {
            $context->reply('recover.not_registered', ['nickname' => $targetNick]);

            return;
        }

        if ($account->isPending()) {
            $context->reply('recover.pending', ['nickname' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('recover.suspended', [
                'nickname' => $targetNick,
                'reason' => $account->getReason() ?? '',
            ]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('recover.forbidden', ['nickname' => $targetNick]);

            return;
        }

        $registry = $context->getRecoveryTokenRegistry();
        if (!$registry->consume($targetNick, $token)) {
            $context->reply('recover.invalid_token', ['nickname' => $targetNick]);

            return;
        }

        $newPassword = SecureToken::hex(12);
        $account->changePasswordWithHasher($newPassword, $this->passwordHasher);
        $this->nickRepository->save($account);

        $identifyCmd = '/msg NickServ IDENTIFY ' . $targetNick . ' ' . $newPassword;
        $context->reply('recover.success_identify', ['identify_cmd' => $identifyCmd]);
        $context->reply('recover.success_then_change');
    }
}
