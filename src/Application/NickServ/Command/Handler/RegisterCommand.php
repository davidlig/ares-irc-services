<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;

/**
 * REGISTER <password> <email>
 *
 * Starts the registration flow for the user's current nickname.
 * The account is created in PENDING status; the user must run VERIFY <token>
 * to activate it. A verification token is logged (email delivery is a
 * future infrastructure concern).
 */
final class RegisterCommand implements NickServCommandInterface
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'REGISTER';
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
        return 'register.syntax';
    }

    public function getHelpKey(): string
    {
        return 'register.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'register.short';
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

        $nick     = $sender->getNick()->value;
        $password = $context->args[0];
        $email    = $context->args[1];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');
            return;
        }

        $existing = $this->nickRepository->findByNick($nick);

        if ($existing !== null) {
            $context->reply(match ($existing->getStatus()) {
                NickStatus::Pending    => 'register.already_pending',
                NickStatus::Forbidden  => 'register.forbidden',
                default                => 'register.already_registered',
            }, ['nickname' => $nick]);
            return;
        }

        $hash      = password_hash($password, PASSWORD_ARGON2ID);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));
        $token     = bin2hex(random_bytes(16));

        $registered = RegisteredNick::createPending(
            nickname:     $nick,
            passwordHash: $hash,
            email:        $email,
            language:     $context->getLanguage(),
            expiresAt:    $expiresAt,
        );

        $this->nickRepository->save($registered);

        // TODO: send $token by email via MailerInterface once implemented.
        // For now the token is stored in context for VERIFY to consume via
        // a shared in-memory PendingVerificationRegistry (wired in services.yaml).
        $context->getPendingVerificationRegistry()->store($nick, $token, $expiresAt);

        $context->reply('register.pending', ['email' => $email]);
    }
}
