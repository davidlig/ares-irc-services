<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsers;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersHandlerInterface;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

use function array_slice;
use function trim;

final class ClearusersCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ClearChannelUsersHandlerInterface $handler,
    ) {}

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

    public function getRequiredPermission(): string
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
        $reason = trim(implode(' ', array_slice($context->args, 1)));
        $kickReason = '' !== $reason ? $reason : $context->trans('clearusers.default_reason');
        $result = $this->handler->handle(new ClearChannelUsers($channelName, $kickReason));
        if (ClearChannelUsersOutcome::ChannelNotRegistered === $result->outcome) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ClearChannelUsersOutcome::ChannelNotOnNetwork === $result->outcome) {
            $context->reply('clearusers.not_on_network', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ClearChannelUsersOutcome::AlreadyEmpty === $result->outcome) {
            $context->reply('clearusers.empty', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply('clearusers.success', ['%channel%' => $channelName, '%count%' => (string) $result->kickedCount]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $channelName,
            reason: '' !== $reason ? $reason : null,
            extra: ['kicked_count' => $result->kickedCount],
        ));
    }
}
