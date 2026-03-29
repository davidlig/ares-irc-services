<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\IdentifyFailedAttemptRegistry;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * IDENTIFY <nickname> <password>.
 *
 * Authenticates a user against a registered nickname.
 * On success: restores the registered nick (if needed) and sets +r mode.
 */
final readonly class IdentifyCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
        private readonly IdentifyFailedAttemptRegistry $failedAttemptRegistry,
        private readonly NickServClientKeyResolver $clientKeyResolver,
        private readonly VhostDisplayResolver $vhostDisplayResolver,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly int $identifyMaxFailedAttempts,
        private readonly int $identifyFailedWindowSeconds,
        private readonly int $identifyLockoutSeconds,
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

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $targetNick = $context->args[0];
        $password = $context->args[1];

        if ($this->isAlreadyIdentified($sender, $targetNick)) {
            $context->reply('identify.already_identified', ['nickname' => $targetNick]);

            return;
        }

        $clientKey = $this->clientKeyResolver->getClientKey($sender);
        if ($this->isLockedOut($context, $clientKey)) {
            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('identify.not_registered', ['nickname' => $targetNick]);

            return;
        }

        if (!$this->validateAccountStatus($context, $account, $targetNick)) {
            return;
        }

        if (!$account->verifyPassword($password)) {
            $this->failedAttemptRegistry->recordFailedAttempt($clientKey, $this->identifyFailedWindowSeconds);
            $context->reply('identify.invalid_credentials');

            return;
        }

        $this->handleSuccessfulIdentification($context, $sender, $account, $targetNick, $clientKey);
    }

    private function isLockedOut(NickServContext $context, string $clientKey): bool
    {
        $remaining = $this->failedAttemptRegistry->getRemainingLockoutSeconds(
            $clientKey,
            $this->identifyMaxFailedAttempts,
            $this->identifyFailedWindowSeconds,
            $this->identifyLockoutSeconds,
        );

        if ($remaining > 0) {
            $minutes = (int) ceil($remaining / 60);
            $context->reply('identify.locked_out', ['minutes' => (string) $minutes]);

            return true;
        }

        return false;
    }

    private function validateAccountStatus(NickServContext $context, RegisteredNick $account, string $targetNick): bool
    {
        if ($account->isPending()) {
            $context->reply('identify.pending', ['nickname' => $targetNick]);

            return false;
        }

        if ($account->isSuspended()) {
            $context->reply('identify.suspended', [
                'nickname' => $targetNick,
                'reason' => $account->getReason() ?? '',
            ]);

            return false;
        }

        if ($account->isForbidden()) {
            $context->reply('identify.forbidden', ['nickname' => $targetNick]);

            return false;
        }

        return true;
    }

    private function handleSuccessfulIdentification(
        NickServContext $context,
        SenderView $sender,
        RegisteredNick $account,
        string $targetNick,
        string $clientKey,
    ): void {
        $this->failedAttemptRegistry->clearFailedAttempts($clientKey);
        $account->markSeen();
        $this->nickRepository->save($account);
        $this->identifiedRegistry->register($sender->uid, $account->getNickname());

        $this->releaseGhostIfPresent($context, $sender, $account, $targetNick);
        $this->applyIrcSession($context, $sender, $account, $targetNick);

        $context->reply('identify.success', ['nickname' => $account->getNickname()]);

        $this->eventDispatcher->dispatch(new NickIdentifiedEvent(
            $account->getId(),
            $account->getNickname(),
            $sender->uid,
        ));
    }

    /**
     * Returns true when the user is already authenticated as the target nick.
     * Registry is cleared on nick change (user loses identification when they change nick).
     */
    private function isAlreadyIdentified(SenderView $sender, string $targetNick): bool
    {
        $registeredNick = $this->identifiedRegistry->findNick($sender->uid);
        if (null !== $registeredNick && 0 === strcasecmp($registeredNick, $targetNick)) {
            return true;
        }

        if ($sender->isIdentified && 0 === strcasecmp($sender->nick, $targetNick)) {
            $this->identifiedRegistry->register($sender->uid, $targetNick);

            return true;
        }

        return false;
    }

    /**
     * Kills the ghost/usurper session holding the target nick, if any.
     */
    private function releaseGhostIfPresent(
        NickServContext $context,
        SenderView $sender,
        RegisteredNick $account,
        string $targetNick,
    ): void {
        $currentHolder = $this->userLookup->findByNick($targetNick);

        if (null === $currentHolder || $sender->uid === $currentHolder->uid) {
            return;
        }

        $killReason = $context->transIn(
            'identify.kill_reason',
            ['nickname' => $targetNick, 'source' => $sender->nick],
            $account->getLanguage(),
        );

        $context->getNotifier()->killUser($currentHolder->uid, $killReason);
        $context->reply('identify.ghost_released', ['nickname' => $targetNick]);
    }

    /**
     * Restores the registered nick (if the user is on a guest nick) and sets
     * the +r (identified) mode via SVS2MODE.
     *
     * For IRCops with forced vhost, personal vhost is NOT applied here.
     * The OperRoleForcedVhostSubscriber (listening to NickIdentifiedEvent) will
     * apply the forced vhost instead.
     */
    private function applyIrcSession(
        NickServContext $context,
        SenderView $sender,
        RegisteredNick $account,
        string $targetNick,
    ): void {
        if (0 !== strcasecmp($sender->nick, $targetNick)) {
            $context->getNotifier()->forceNick($sender->uid, $account->getNickname());
        }

        $context->getNotifier()->setUserAccount($sender->uid, $account->getNickname());

        if ($this->hasForcedVhost($account->getId())) {
            return;
        }

        $displayVhost = $this->vhostDisplayResolver->getDisplayVhost($account->getVhost());
        $context->getNotifier()->setUserVhost($sender->uid, $displayVhost, $sender->serverSid);
    }

    private function hasForcedVhost(int $nickId): bool
    {
        $ircop = $this->ircopRepository->findByNickId($nickId);
        if (null === $ircop) {
            return false;
        }

        $pattern = $ircop->getRole()->getForcedVhostPattern();

        return null !== $pattern && '' !== $pattern && ForcedVhost::isValidPattern($pattern);
    }
}
