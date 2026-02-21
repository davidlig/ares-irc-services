<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Exception\NickAlreadyRegisteredException;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * REGISTER <password> <email>
 *
 * Registers the user's current nickname. On success, the IRCd +r mode
 * is set and the user is considered identified.
 */
final class RegisterCommand implements NickServCommandInterface
{
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
        $sender   = $context->sender;
        $password = $context->args[0];
        $email    = $context->args[1];

        if ($sender === null) {
            return;
        }

        $nick = $sender->getNick()->value;

        if ($this->nickRepository->existsByNick($nick)) {
            throw new NickAlreadyRegisteredException($nick);
        }

        // Basic email validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');
            return;
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $registered = new RegisteredNick(
            nickname:     $nick,
            passwordHash: $hash,
            email:        $email,
            language:     $context->getLanguage(),
        );

        $this->nickRepository->save($registered);

        $context->getNotifier()->setUserAccount($sender->uid->value, $nick);

        $context->reply('register.success', ['nickname' => $nick]);
    }
}
