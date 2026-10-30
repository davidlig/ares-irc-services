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
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;

final class ClearaccessCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;

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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'clearaccess.syntax',
            arguments: [
                new Argument('channel'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'clearaccess.help';
    }

    public function getExampleKey(): string
    {
        return 'clearaccess.example';
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
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new ClearChannelAccess(
            channelName: $channelName,
            actorNickname: $context->sender->nick,
            occurredAt: new DateTimeImmutable(),
        ));
        if (ClearChannelAccessOutcome::ChannelNotRegistered === $result->outcome) {
            $context->reply('presentation.error.channel_not_registered', ['%channel%' => new Fragment((string) $channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ClearChannelAccessOutcome::AlreadyEmpty === $result->outcome) {
            $context->reply('clearaccess.empty', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Information);

            return CommandOutcome::rejected();
        }

        $context->reply('clearaccess.success', ['%channel%' => new Fragment($channelName, Component::Emphasis), '%count%' => new Fragment((string) $result->removedCount, Component::Emphasis)], Role::Success);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['count' => $result->removedCount]));
    }
}
