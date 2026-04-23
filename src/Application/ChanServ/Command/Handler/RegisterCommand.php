<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function strtolower;

/**
 * REGISTER <#channel> <description>.
 *
 * Registers a channel. User must be identified. ChanServ joins with max level
 * and sets +nt if the channel has no MLOCK.
 */
final readonly class RegisterCommand implements ChanServCommandInterface
{
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
            throw ChannelAlreadyRegisteredException::forChannel($channelName);
        }

        $channelView = $context->getChannelView($channelName);
        if (null === $channelView) {
            $context->reply('register.channel_not_on_network', ['%channel%' => $channelName]);

            return;
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return;
        }

        $sender = $context->sender;
        $isPrivileged = (null !== $sender && $sender->isOper)
            || $this->rootRegistry->isRoot($context->sender?->nick ?? '');

        if (!$isPrivileged) {
            $remainingCooldown = $this->throttleRegistry->getRemainingCooldownSeconds(
                $senderAccount->getId(),
                $this->registerMinIntervalSeconds
            );
            if ($remainingCooldown > 0) {
                $minutes = (int) ceil($remainingCooldown / 60);
                $context->reply('register.throttled', ['minutes' => (string) $minutes]);

                return;
            }

            $existingChannels = $this->channelRepository->findByFounderNickId($senderAccount->getId());
            if (count($existingChannels) >= $this->maxChannelsPerNick) {
                $context->reply('register.limit_exceeded', ['%max%' => (string) $this->maxChannelsPerNick]);

                return;
            }
        }

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
            $channelNameLower,
        ));

        $context->reply('register.success', ['%channel%' => $channelName]);
    }
}
