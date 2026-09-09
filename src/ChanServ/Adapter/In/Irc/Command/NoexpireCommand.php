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

use function in_array;
use function strtoupper;

final class NoexpireCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use BuildsChannelLifecycleRequest;

    public function __construct(
        private readonly ManageChannelLifecycleHandlerInterface $handler,
    ) {}

    public function getName(): string
    {
        return 'NOEXPIRE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'noexpire.syntax';
    }

    public function getHelpKey(): string
    {
        return 'noexpire.help';
    }

    public function getOrder(): int
    {
        return 76;
    }

    public function getShortDescKey(): string
    {
        return 'noexpire.short';
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
        return ChanServPermission::NOEXPIRE;
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
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

    /**
     * @return array<string, mixed>
     */
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
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }

        $action = strtoupper($context->args[1]);

        if (!in_array($action, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $enabled = 'ON' === $action;
        $result = $this->handler->handle($this->lifecycleRequest(
            $context,
            $channelName,
            $enabled ? ChannelLifecycleAction::EnableNoExpire : ChannelLifecycleAction::DisableNoExpire,
        ));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('noexpire.not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::ChannelForbidden === $result->outcome) {
            $context->reply('noexpire.forbidden', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::ChannelSuspended === $result->outcome) {
            $context->reply('noexpire.suspended', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $auditData = new IrcopAuditData(
            target: $channelName,
            extra: ['option' => $action],
        );

        $context->reply(
            $enabled ? 'noexpire.success_on' : 'noexpire.success_off',
            ['%channel%' => $channelName],
        );

        return CommandOutcome::success($auditData);
    }
}
