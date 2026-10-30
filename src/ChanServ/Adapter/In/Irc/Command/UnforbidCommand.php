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
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

final class UnforbidCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use BuildsChannelLifecycleRequest;

    public function __construct(
        private readonly ManageChannelLifecycleHandlerInterface $handler,
    ) {}

    public function getName(): string
    {
        return 'UNFORBID';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'unforbid.syntax',
            arguments: [
                new Argument('channel'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'unforbid.help';
    }

    public function getExampleKey(): string
    {
        return 'unforbid.example';
    }

    public function getOrder(): int
    {
        return 80;
    }

    public function getShortDescKey(): string
    {
        return 'unforbid.short';
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
        return ChanServPermission::FORBID;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    /** Whether this command is allowed on forbidden channels. */
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

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle($this->lifecycleRequest($context, $channelName, ChannelLifecycleAction::Unforbid));
        if (ChannelLifecycleOutcome::NotForbidden === $result->outcome) {
            $context->reply('unforbid.not_forbidden', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        $context->reply('unforbid.success', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }
}
