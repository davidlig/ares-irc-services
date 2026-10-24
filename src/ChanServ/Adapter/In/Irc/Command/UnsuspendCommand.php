<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleAction;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleOutcome;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

final class UnsuspendCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use BuildsChannelLifecycleRequest;

    public function __construct(
        private readonly ManageChannelLifecycleHandlerInterface $handler,
    ) {}

    public function getName(): string
    {
        return 'UNSUSPEND';
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
        return 'unsuspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unsuspend.help';
    }

    public function getOrder(): int
    {
        return 78;
    }

    public function getShortDescKey(): string
    {
        return 'unsuspend.short';
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
        return ChanServPermission::SUSPEND;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle($this->lifecycleRequest($context, $channelName, ChannelLifecycleAction::Unsuspend));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('unsuspend.not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::NotSuspended === $result->outcome) {
            $context->reply('unsuspend.not_suspended', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply('unsuspend.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }
}
