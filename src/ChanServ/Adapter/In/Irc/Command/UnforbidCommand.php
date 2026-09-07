<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

final class UnforbidCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ChannelForbiddenService $forbiddenService,
    ) {}

    public function getName(): string
    {
        return 'UNFORBID';
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
        return 'unforbid.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unforbid.help';
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
            $context->reply('error.invalid_channel');

            return CommandOutcome::rejected();
        }

        $success = $this->forbiddenService->unforbid($channelName, $context->sender->nick);

        if (!$success) {
            $context->reply('unforbid.not_forbidden', ['%channel%' => $channelName]);

            return CommandOutcome::rejected();
        }

        $context->reply('unforbid.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }
}
