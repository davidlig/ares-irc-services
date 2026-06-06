<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChanDropService;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final class RestoreCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChanDropService $dropService,
    ) {}

    public function getName(): string
    {
        return 'RESTORE';
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
        return 'restore.syntax';
    }

    public function getHelpKey(): string
    {
        return 'restore.help';
    }

    public function getOrder(): int
    {
        return 76;
    }

    public function getShortDescKey(): string
    {
        return 'restore.short';
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
        return ChanServPermission::RESTORE;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function allowsForbiddenChannel(): bool
    {
        return true;
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

        $validation = $this->validateRestore($context);
        if (null === $validation) {
            return;
        }

        $this->performRestore($context, ...$validation);
    }

    /** @return array{string, object}|null */
    private function validateRestore(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return null;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);
        if (null === $channel) {
            $context->reply('restore.not_registered', ['%channel%' => $channelName]);

            return null;
        }

        return $this->checkRestoreDeletionStatus($context, $channel, $channelName);
    }

    /** @return array{string, object}|null */
    private function checkRestoreDeletionStatus(ChanServContext $context, object $channel, string $channelName): ?array
    {
        if (!$channel->isPendingDeletion()) {
            $context->reply('restore.not_pending_deletion', ['%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $channel];
    }

    private function performRestore(ChanServContext $context, string $channelName, object $channel): void
    {
        $this->dropService->restoreChannel($channel, $context->sender->nick);
        $this->auditData = new IrcopAuditData(target: $channelName);

        $context->reply('restore.success', ['%channel%' => $channelName]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
