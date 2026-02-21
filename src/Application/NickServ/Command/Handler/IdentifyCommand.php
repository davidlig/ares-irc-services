<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * IDENTIFY [nickname] <password>
 *
 * Authenticates a user against a registered nickname.
 * If nickname is omitted, uses the user's current nick.
 * On success: sets +r mode on the IRCd.
 */
final class IdentifyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function getName(): string
    {
        return 'IDENTIFY';
    }

    public function getAliases(): array
    {
        return ['ID'];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'identify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'identify.help';
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

        // IDENTIFY <password>  → identify as current nick
        // IDENTIFY <nickname> <password> → identify as a different nick
        if (count($context->args) >= 2) {
            $targetNick = $context->args[0];
            $password   = $context->args[1];
        } else {
            $targetNick = $sender->getNick()->value;
            $password   = $context->args[0];
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if ($account === null) {
            $context->reply('identify.not_registered', ['nickname' => $targetNick]);
            return;
        }

        if (!$account->verifyPassword($password)) {
            $context->reply('identify.invalid_credentials');
            return;
        }

        $account->markSeen();
        $this->nickRepository->save($account);

        // Step 1 — restore the registered nick (if the user is on a guest nick).
        // forceNick() marks the UID in PendingNickRestoreRegistry so that the
        // NickProtectionSubscriber ignores the NICK echo without triggering protection.
        $currentNick = $sender->getNick()->value;
        if (strtolower($currentNick) !== strtolower($targetNick)) {
            $context->getNotifier()->forceNick($sender->uid->value, $account->getNickname());
        }

        // Step 2 — set +r on the IRCd (SVS2MODE, UnrealIRCd 6).
        $context->getNotifier()->setUserAccount($sender->uid->value, $account->getNickname());

        $context->reply('identify.success', ['nickname' => $account->getNickname()]);
    }
}
