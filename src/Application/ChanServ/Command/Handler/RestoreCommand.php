<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChanDropService;
use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function assert;

final class RestoreCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
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

    public function getRequiredPermission(): string
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $validation = $this->validateRestore($context);
        if (null === $validation) {
            return CommandOutcome::rejected();
        }

        return $this->performRestore($context, ...$validation);
    }

    /** @return array{string, RegisteredChannel}|null */
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

    /** @return array{string, RegisteredChannel}|null */
    private function checkRestoreDeletionStatus(ChanServContext $context, RegisteredChannel $channel, string $channelName): ?array
    {
        if (!$channel->isPendingDeletion()) {
            $context->reply('restore.not_pending_deletion', ['%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $channel];
    }

    private function performRestore(ChanServContext $context, string $channelName, RegisteredChannel $channel): CommandOutcome
    {
        $sender = $context->sender;
        assert(null !== $sender);

        $this->dropService->restoreChannel($channel, $sender->nick);
        $context->reply('restore.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }
}
