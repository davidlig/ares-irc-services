<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function strtolower;

final class ClearaccessCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChannelAccessRepositoryInterface $accessRepository,
    ) {}

    public function getName(): string
    {
        return 'CLEARACCESS';
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
        return 'clearaccess.syntax';
    }

    public function getHelpKey(): string
    {
        return 'clearaccess.help';
    }

    public function getOrder(): int
    {
        return 74;
    }

    public function getShortDescKey(): string
    {
        return 'clearaccess.short';
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
        return ChanServPermission::CLEARACCESS;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

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
        if (null === $context->sender) {
            return;
        }

        $validation = $this->validateClearaccess($context);
        if (null === $validation) {
            return;
        }

        [$channelName, $channel, $count] = $validation;
        $this->accessRepository->deleteByChannelId($channel->getId());

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            extra: ['count' => $count],
        );

        $context->reply('clearaccess.success', [
            '%channel%' => $channelName,
            '%count%' => $count,
        ]);
    }

    /** @return array{string, object, int}|null */
    private function validateClearaccess(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return null;
        }

        return $this->validateClearaccessChannel($context, $channelName);
    }

    /** @return array{string, object, int}|null */
    private function validateClearaccessChannel(ChanServContext $context, string $channelName): ?array
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));

        if (null === $channel) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return null;
        }

        $count = $this->accessRepository->countByChannel($channel->getId());

        if (0 === $count) {
            $context->reply('clearaccess.empty', ['%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $channel, $count];
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
