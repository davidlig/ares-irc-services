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

use function array_slice;
use function implode;
use function trim;

final class SuspendCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use BuildsChannelLifecycleRequest;

    public function __construct(
        private readonly ManageChannelLifecycleHandlerInterface $handler,
    ) {}

    public function getName(): string
    {
        return 'SUSPEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'suspend.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('duration'),
                new Argument('reason', rest: true, nonBlank: true),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'suspend.help';
    }

    public function getExampleKey(): string
    {
        return 'suspend.example';
    }

    public function getOrder(): int
    {
        return 77;
    }

    public function getShortDescKey(): string
    {
        return 'suspend.short';
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
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }

        if (!$this->validateArguments($context)) {
            return CommandOutcome::rejected();
        }

        $durationStr = $context->args[1] ?? '';

        $reasonParts = array_slice($context->args, 2);
        $reason = trim(implode(' ', $reasonParts));

        $result = $this->handler->handle($this->lifecycleRequest(
            $context,
            $channelName,
            ChannelLifecycleAction::Suspend,
            reason: $reason,
            duration: $durationStr,
        ));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('presentation.error.channel_not_registered', ['%channel%' => new Fragment((string) $channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::AlreadySuspended === $result->outcome) {
            $context->reply('suspend.already_suspended', ['%channel%' => new Fragment($channelName, Component::Emphasis)], Role::Error);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::InvalidDuration === $result->outcome) {
            $context->reply('suspend.invalid_duration', role: Role::Error);

            return CommandOutcome::rejected();
        }

        $durationDisplay = null === $result->expiresAt
            ? $context->trans('label.permanent')
            : $context->formatDate($result->expiresAt);

        $auditData = new IrcopAuditData(
            target: $channelName,
            reason: $reason,
            extra: ['duration' => $durationStr],
        );

        $context->reply('suspend.success', [
            '%channel%' => new Fragment($channelName, Component::Emphasis),
            '%duration%' => new Fragment($durationDisplay, Component::Emphasis),
        ], Role::Success);

        return CommandOutcome::success($auditData);
    }
}
