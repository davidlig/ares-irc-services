<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * IDENTIFY <nickname> <password>
 *
 * Authenticates a user against a registered nickname.
 * On success: restores the registered nick (if needed) and sets +r mode.
 */
final class IdentifyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
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
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'identify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'identify.help';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'identify.short';
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

        // IDENTIFY <nickname> <password> — nickname is always required
        $targetNick = $context->args[0];
        $password   = $context->args[1];

        // Already identified as this nick — skip silently.
        if ($sender->isIdentified() && strcasecmp($sender->getNick()->value, $targetNick) === 0) {
            return;
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

        // Track this session so onUserQuit can find the account even if the
        // user later changes to a different nick before disconnecting.
        $this->identifiedRegistry->register($sender->uid->value, $account->getNickname());

        // Kill any ghost/usurper session currently holding the target nick.
        try {
            $currentHolder = $this->userRepository->findByNick(new Nick($targetNick));
            if ($currentHolder !== null && $currentHolder->uid->value !== $sender->uid->value) {
                // Translate the kill reason in the registered account's language so
                // the message is meaningful in the server logs. Include the source nick
                // so it is clear who triggered the reclaim.
                $killReason = $context->transIn(
                    'identify.kill_reason',
                    [
                        'nickname' => $targetNick,
                        'source'   => $sender->getNick()->value,
                    ],
                    $account->getLanguage(),
                );

                $context->getNotifier()->killUser($currentHolder->uid->value, $killReason);
                $context->reply('identify.ghost_released', ['nickname' => $targetNick]);
            }
        } catch (\InvalidArgumentException) {
            // Unreachable in practice: $targetNick passed NickServ's arg validation.
        }

        // Restore registered nick if the sender is on a different nick (e.g. a guest nick).
        // forceNick() marks the UID in PendingNickRestoreRegistry so NickProtectionSubscriber
        // ignores the resulting NICK echo without triggering protection again.
        if (strcasecmp($sender->getNick()->value, $targetNick) !== 0) {
            $context->getNotifier()->forceNick($sender->uid->value, $account->getNickname());
        }

        // Set +r (registered/identified) mode via SVS2MODE (UnrealIRCd 6).
        $context->getNotifier()->setUserAccount($sender->uid->value, $account->getNickname());

        $context->reply('identify.success', ['nickname' => $account->getNickname()]);
    }
}
