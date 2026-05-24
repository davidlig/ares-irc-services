<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function in_array;
use function strtolower;

/**
 * REGISTER <#channel> <description>.
 *
 * Registers a channel. User must be identified. ChanServ joins with max level
 * and sets +nt if the channel has no MLOCK.
 */
final readonly class RegisterCommand implements ChanServCommandInterface
{
    private const array REQUIRED_REGISTER_PREFIX_MODES = ['q', 'a', 'o'];

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private ChannelRegisterThrottleRegistry $throttleRegistry,
        private EventDispatcherInterface $eventDispatcher,
        private RootUserRegistry $rootRegistry,
        private int $maxChannelsPerNick = 3,
        private int $registerMinIntervalSeconds = 21600,
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

    public function getRequiredPermission(): ?string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $description = implode(' ', array_slice($context->args, 1));
        $channelNameLower = strtolower($channelName);

        if ($this->channelRepository->existsByChannelName($channelNameLower)) {
            $existing = $this->channelRepository->findByChannelName($channelNameLower);
            if (null !== $existing && $existing->isPendingDeletion()) {
                $context->reply('register.pending_deletion', ['%channel%' => $channelName]);

                return;
            }

            throw ChannelAlreadyRegisteredException::forChannel($channelName);
        }

        $validation = $this->validateRegisterPrerequisites($context, $channelName, $channelNameLower);
        if (null === $validation) {
            return;
        }

        $this->performRegister($context, $channelName, $description, $validation);
    }

    /** @return array{senderAccount: object, isPrivileged: bool}|null */
    private function validateRegisterPrerequisites(ChanServContext $context, string $channelName, string $channelNameLower): ?array
    {
        $channelView = $context->getChannelView($channelName);
        if (null === $channelView) {
            $context->reply('register.channel_not_on_network', ['%channel%' => $channelName]);

            return null;
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return null;
        }

        return $this->validateRegisterPermissions($context, $channelName, $channelView, $senderAccount);
    }

    /** @return array{senderAccount: object, isPrivileged: bool}|null */
    private function validateRegisterPermissions(ChanServContext $context, string $channelName, $channelView, $senderAccount): ?array
    {
        $sender = $context->sender;
        $isPrivileged = (null !== $sender && $sender->isOper)
            || $this->rootRegistry->isRoot($context->sender?->nick ?? '');

        if (!$isPrivileged && !$this->senderHasRequiredChannelPrefix($channelView, $sender?->uid ?? '')) {
            $context->reply('register.insufficient_channel_rank', ['%channel%' => $channelName]);

            return null;
        }

        if (!$isPrivileged) {
            return $this->validateRegisterLimits($context, $channelName, ['senderAccount' => $senderAccount, 'isPrivileged' => $isPrivileged]);
        }

        return ['senderAccount' => $senderAccount, 'isPrivileged' => $isPrivileged];
    }

    /** @return array{senderAccount: object, isPrivileged: bool}|null */
    private function validateRegisterLimits(ChanServContext $context, string $channelName, array $prelim): ?array
    {
        $senderAccount = $prelim['senderAccount'];
        $remainingCooldown = $this->throttleRegistry->getRemainingCooldownSeconds(
            $senderAccount->getId(),
            $this->registerMinIntervalSeconds
        );
        if ($remainingCooldown > 0) {
            $minutes = (int) ceil($remainingCooldown / 60);
            $context->reply('register.throttled', ['minutes' => (string) $minutes]);

            return null;
        }

        $existingChannels = $this->channelRepository->findByFounderNickId($senderAccount->getId());
        if (count($existingChannels) >= $this->maxChannelsPerNick) {
            $context->reply('register.limit_exceeded', ['%max%' => (string) $this->maxChannelsPerNick]);

            return null;
        }

        return $prelim;
    }

    private function performRegister(ChanServContext $context, string $channelName, string $description, array $validation): void
    {
        $senderAccount = $validation['senderAccount'];
        $isPrivileged = $validation['isPrivileged'];

        $channel = RegisteredChannel::register(
            $channelName,
            $senderAccount->getId(),
            $description,
        );
        $this->channelRepository->save($channel);

        if (!$isPrivileged) {
            $this->throttleRegistry->recordRegistration($senderAccount->getId());
        }

        foreach (ChannelLevel::DEFAULTS as $key => $value) {
            $level = new ChannelLevel($channel->getId(), $key, $value);
            $this->levelRepository->save($level);
        }

        $this->eventDispatcher->dispatch(new ChannelRegisteredEvent(
            $channel->getId(),
            $channelName,
            strtolower($channelName),
        ));

        $context->reply('register.success', ['%channel%' => $channelName]);
    }

    private function senderHasRequiredChannelPrefix(ChannelView $channelView, string $senderUid): bool
    {
        foreach ($channelView->members as $member) {
            if (($member['uid'] ?? '') !== $senderUid) {
                continue;
            }

            $prefixLetters = $member['prefixLetters'] ?? [$member['roleLetter'] ?? ''];
            foreach ($prefixLetters as $letter) {
                if (in_array($letter, self::REQUIRED_REGISTER_PREFIX_MODES, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
