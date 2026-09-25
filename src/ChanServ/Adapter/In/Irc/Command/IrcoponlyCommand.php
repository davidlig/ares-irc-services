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

final readonly class IrcoponlyCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
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

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'ircoponly.syntax';
    }

    public function getHelpKey(): string
    {
        return 'ircoponly.help';
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
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }
        $value = strtoupper($context->args[1]);
        if (!in_array($value, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }
        $enabled = 'ON' === $value;
        $result = $this->handler->handle($this->lifecycleRequest(
            $context,
            $channelName,
            $enabled ? ChannelLifecycleAction::EnableIrcopOnly : ChannelLifecycleAction::DisableIrcopOnly,
        ));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('error.channel_not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::ChannelForbidden === $result->outcome) {
            $context->reply('forbid.channel_forbidden', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        $context->reply($enabled ? 'ircoponly.on' : 'ircoponly.off', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['option' => $value]));
    }
}
