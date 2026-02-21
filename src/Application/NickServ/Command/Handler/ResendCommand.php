<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * RESEND
 *
 * Regenerates and resends the verification token to the email address
 * provided during REGISTER. Only works while the account is in PENDING status.
 */
final class ResendCommand implements NickServCommandInterface
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
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
        if ($sender === null) {
            return;
        }

        $nick    = $sender->getNick()->value;
        $account = $this->nickRepository->findByNick($nick);

        if ($account === null || !$account->isPending()) {
            $context->reply('resend.no_pending');
            return;
        }

        $token     = bin2hex(random_bytes(16));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));

        $context->getPendingVerificationRegistry()->store($nick, $token, $expiresAt);

        // TODO: deliver $token via MailerInterface once implemented.
        $context->reply('resend.success', ['email' => $account->getEmail() ?? '']);
    }
}
