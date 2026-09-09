<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleAction;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleOutcome;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

use function strcasecmp;

final class DropCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use BuildsChannelLifecycleRequest;

    public function __construct(
        private readonly ManageChannelLifecycleHandlerInterface $handler,
        private readonly ?ChanAuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    public function getName(): string
    {
        return 'DROP';
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
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 75;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
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
        return ChanServPermission::DROP;
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
            $context->reply('drop.invalid_channel');

            return CommandOutcome::rejected();
        }
        $force = isset($context->args[1]) && 0 === strcasecmp($context->args[1], 'force');
        if ($force && (null === $this->authorizationChecker || !$this->authorizationChecker->isGranted(ChanServPermission::DROP_FORCE, $context))) {
            $context->reply('error.permission_denied');

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle($this->lifecycleRequest($context, $channelName, ChannelLifecycleAction::Drop, force: $force));
        if (ChannelLifecycleOutcome::NotRegistered === $result->outcome) {
            $context->reply('drop.not_registered', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }
        if (ChannelLifecycleOutcome::PendingDeletion === $result->outcome) {
            $context->reply('drop.pending_deletion', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply($force ? 'drop.force_success' : 'drop.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: $force ? ['force' => true] : []));
    }
}
