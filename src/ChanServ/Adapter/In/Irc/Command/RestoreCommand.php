<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

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
