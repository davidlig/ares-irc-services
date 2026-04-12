<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function array_slice;
use function count;
use function strtolower;
use function trim;

final class ClearusersCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChanServNotifierInterface $notifier,
    ) {
    }

    public function getName(): string
    {
        return 'CLEARUSERS';
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
        return 'clearusers.syntax';
    }

    public function getHelpKey(): string
    {
        return 'clearusers.help';
    }

    public function getOrder(): int
    {
        return 73;
    }

    public function getShortDescKey(): string
    {
        return 'clearusers.short';
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
        return ChanServPermission::CLEARUSERS;
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

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));

        if (null === $channel) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return;
        }

        $view = $context->getChannelView($channelName);

        if (null === $view) {
            $context->reply('clearusers.not_on_network', ['%channel%' => $channelName]);

            return;
        }

        $reason = trim(implode(' ', array_slice($context->args, 1)));
        $kickReason = '' !== $reason ? $reason : $context->trans('clearusers.default_reason');

        $members = $view->members;

        if (0 === count($members)) {
            $context->reply('clearusers.empty', ['%channel%' => $channelName]);

            return;
        }

        $count = count($members);

        foreach ($members as $member) {
            $this->notifier->kickFromChannel(
                $channelName,
                $member['uid'],
                $kickReason,
            );
        }

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            reason: '' !== $reason ? $reason : null,
            extra: ['kicked_count' => $count],
        );

        $context->reply('clearusers.success', [
            '%channel%' => $channelName,
            '%count%' => (string) $count,
        ]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
