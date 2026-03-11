<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command\Handler;

use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServContext;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\MemoSettings;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;

use function str_starts_with;
use function strtolower;

/**
 * DISABLE [#canal].
 * For nick: disable memo reception for own nick. For channel: founder only.
 */
final readonly class DisableCommand implements MemoServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {
    }

    public function getName(): string
    {
        return 'DISABLE';
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
        return 'disable.syntax';
    }

    public function getHelpKey(): string
    {
        return 'disable.help';
    }

    public function getOrder(): int
    {
        return 7;
    }

    public function getShortDescKey(): string
    {
        return 'disable.short';
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

    public function execute(MemoServContext $context): void
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount || null === $context->sender) {
            $context->reply('error.not_identified');

            return;
        }

        $first = $context->args[0] ?? null;
        if (null !== $first && str_starts_with($first, '#')) {
            $this->disableChannel($context, $first, $senderAccount->getId());

            return;
        }

        $this->disableNick($context, $senderAccount->getId());
    }

    private function disableNick(MemoServContext $context, int $nickId): void
    {
        $settings = $this->memoSettingsRepository->findByTargetNick($nickId);
        if (null !== $settings) {
            if (!$settings->isEnabled()) {
                $context->reply('disable.already_disabled_nick');

                return;
            }
            $settings->setEnabled(false);
            $this->memoSettingsRepository->save($settings);
        } else {
            $settings = new MemoSettings($nickId, null, false);
            $this->memoSettingsRepository->save($settings);
        }
        $context->reply('disable.disabled_nick');
    }

    private function disableChannel(MemoServContext $context, string $channelName, int $senderNickId): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            $context->reply('disable.channel_not_registered', ['channel' => $channelName]);

            return;
        }
        if (!$channel->isFounder($senderNickId)) {
            $context->reply('disable.founder_only', ['channel' => $channelName]);

            return;
        }
        $settings = $this->memoSettingsRepository->findByTargetChannel($channel->getId());
        if (null !== $settings) {
            if (!$settings->isEnabled()) {
                $context->reply('disable.already_disabled_channel', ['channel' => $channelName]);

                return;
            }
            $settings->setEnabled(false);
        } else {
            $settings = new MemoSettings(null, $channel->getId(), false);
        }
        $this->memoSettingsRepository->save($settings);
        $context->reply('disable.disabled_channel', ['channel' => $channel->getName()]);
    }
}
