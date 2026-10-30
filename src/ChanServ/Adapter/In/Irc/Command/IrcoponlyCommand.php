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

use function strtoupper;

final readonly class IrcoponlyCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use BuildsChannelLifecycleRequest;

    public function __construct(private ManageChannelLifecycleHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'IRCOPONLY';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'ircoponly.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('state', alternatives: ['ON', 'OFF']),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'ircoponly.help';
    }

    public function getExampleKey(): string
    {
        return 'ircoponly.example';
    }

    public function getOrder(): int
    {
        return 77;
    }

    public function getShortDescKey(): string
    {
        return 'ircoponly.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return ChanServPermission::IRCOPONLY;
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

    /** @return array<string, mixed> */
    public function getHelpParams(): array
    {
        return [];
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

        if (!$this->validateArguments($context)) {
            return CommandOutcome::rejected();
        }

        $value = strtoupper($context->args[1]);
        $enabled = 'ON' === $value;
        $result = $this->handler->handle($this->lifecycleRequest(
            $context,
            $channelName,
            $enabled ? ChannelLifecycleAction::EnableIrcopOnly : ChannelLifecycleAction::DisableIrcopOnly,
        ));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('presentation.error.channel_not_registered', ['%channel%' => new Fragment((string) $channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::ChannelForbidden === $result->outcome) {
            $context->reply('forbid.channel_forbidden', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        $context->reply($enabled ? 'ircoponly.on' : 'ircoponly.off', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Success);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['option' => $value]));
    }
}
