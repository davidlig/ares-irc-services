<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * VERIFY <token>.
 *
 * Completes the registration started by REGISTER by validating the
 * verification token sent to the user's email. On success the account
 * transitions from PENDING to REGISTERED and the user is identified.
 */
final readonly class VerifyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
    ) {
    }

    public function getName(): string
    {
        return 'VERIFY';
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
        return 'verify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'verify.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'verify.short';
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

        $token = $context->args[0];
        $nick = $sender->getNick()->value;

        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isPending()) {
            $context->reply('verify.no_pending');

            return;
        }

        if (!$context->getPendingVerificationRegistry()->consume($nick, $token)) {
            $context->reply('verify.invalid_token');

            return;
        }

        $account->activate();
        $this->nickRepository->save($account);

        $this->identifiedRegistry->register($sender->uid->value, $account->getNickname());
        $context->getNotifier()->setUserAccount($sender->uid->value, $account->getNickname());

        $context->reply('verify.success', ['nickname' => $account->getNickname()]);
    }
}
