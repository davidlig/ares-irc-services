<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccess;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessHandlerInterface;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessOutcome;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

final class ClearaccessCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ClearChannelAccessHandlerInterface $handler,
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

    public function getRequiredPermission(): string
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

        $result = $this->handler->handle(new ClearChannelAccess($channelName));
        if (ClearChannelAccessOutcome::ChannelNotRegistered === $result->outcome) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ClearChannelAccessOutcome::AlreadyEmpty === $result->outcome) {
            $context->reply('clearaccess.empty', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply('clearaccess.success', ['%channel%' => $channelName, '%count%' => $result->removedCount]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['count' => $result->removedCount]));
    }
}
