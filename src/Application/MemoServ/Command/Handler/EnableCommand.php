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
 * ENABLE [#canal].
 * For nick: enable memo reception for own nick. For channel: founder only.
 */
final readonly class EnableCommand implements MemoServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {
    }

    public function getName(): string
    {
        return 'ENABLE';
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
        return 'enable.syntax';
    }

    public function getHelpKey(): string
    {
        return 'enable.help';
    }

    public function getOrder(): int
    {
        return 6;
    }

    public function getShortDescKey(): string
    {
        return 'enable.short';
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
            $this->enableChannel($context, $first, $senderAccount->getId());

            return;
        }

        $this->enableNick($context, $senderAccount->getId());
    }

    private function enableNick(MemoServContext $context, int $nickId): void
    {
        $settings = $this->memoSettingsRepository->findByTargetNick($nickId);
        if (null !== $settings) {
            if ($settings->isEnabled()) {
                $context->reply('enable.already_enabled_nick');

                return;
            }
            $settings->enable();
        } else {
            $settings = new MemoSettings($nickId, null, true);
        }
        $this->memoSettingsRepository->save($settings);
        $context->reply('enable.enabled_nick');
    }

    private function enableChannel(MemoServContext $context, string $channelName, int $senderNickId): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            $context->reply('enable.channel_not_registered', ['channel' => $channelName]);

            return;
        }
        if (!$channel->isFounder($senderNickId)) {
            $context->reply('enable.founder_only', ['channel' => $channelName]);

            return;
        }
        $settings = $this->memoSettingsRepository->findByTargetChannel($channel->getId());
        if (null !== $settings) {
            if ($settings->isEnabled()) {
                $context->reply('enable.already_enabled_channel', ['channel' => $channelName]);

                return;
            }
            $settings->enable();
        } else {
            $settings = new MemoSettings(null, $channel->getId(), true);
        }
        $this->memoSettingsRepository->save($settings);
        $context->reply('enable.enabled_channel', ['channel' => $channel->getName()]);
    }
}
