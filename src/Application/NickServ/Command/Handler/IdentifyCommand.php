<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Entity\RegisteredNick;
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

        $targetNick = $context->args[0];
        $password   = $context->args[1];

        if ($this->isAlreadyIdentified($sender, $targetNick)) {
            $context->reply('identify.already_identified', ['nickname' => $targetNick]);
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
        $this->identifiedRegistry->register($sender->uid->value, $account->getNickname());

        $this->releaseGhostIfPresent($context, $sender, $account, $targetNick);
        $this->applyIrcSession($context, $sender, $account, $targetNick);

        $context->reply('identify.success', ['nickname' => $account->getNickname()]);
    }

    /**
     * Returns true when the user is already authenticated as the target nick,
     * using the in-memory registry as primary source of truth and the IRCd +r
     * mode as fallback after a service restart (when the registry is empty).
     */
    private function isAlreadyIdentified(NetworkUser $sender, string $targetNick): bool
    {
        $registeredNick = $this->identifiedRegistry->findNick($sender->uid->value);
        if ($registeredNick !== null && strcasecmp($registeredNick, $targetNick) === 0) {
            return true;
        }

        if ($sender->isIdentified() && strcasecmp($sender->getNick()->value, $targetNick) === 0) {
            $this->identifiedRegistry->register($sender->uid->value, $targetNick);
            return true;
        }

        return false;
    }

    /**
     * Kills the ghost/usurper session holding the target nick, if any.
     */
    private function releaseGhostIfPresent(
        NickServContext $context,
        NetworkUser $sender,
        RegisteredNick $account,
        string $targetNick,
    ): void {
        try {
            $currentHolder = $this->userRepository->findByNick(new Nick($targetNick));
        } catch (\InvalidArgumentException) {
            return;
        }

        if ($currentHolder === null || $currentHolder->uid->value === $sender->uid->value) {
            return;
        }

        $killReason = $context->transIn(
            'identify.kill_reason',
            ['nickname' => $targetNick, 'source' => $sender->getNick()->value],
            $account->getLanguage(),
        );

        $context->getNotifier()->killUser($currentHolder->uid->value, $killReason);
        $context->reply('identify.ghost_released', ['nickname' => $targetNick]);
    }

    /**
     * Restores the registered nick (if the user is on a guest nick) and sets
     * the +r (identified) mode via SVS2MODE.
     */
    private function applyIrcSession(
        NickServContext $context,
        NetworkUser $sender,
        RegisteredNick $account,
        string $targetNick,
    ): void {
        if (strcasecmp($sender->getNick()->value, $targetNick) !== 0) {
            $context->getNotifier()->forceNick($sender->uid->value, $account->getNickname());
        }

        $context->getNotifier()->setUserAccount($sender->uid->value, $account->getNickname());
    }
}
