<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;

final class UnforbidCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly ChannelForbiddenService $forbiddenService,
    ) {
    }

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

    public function getRequiredPermission(): ?string
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

    public function execute(ChanServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $success = $this->forbiddenService->unforbid($channelName, $context->sender->nick);

        if (!$success) {
            $context->reply('unforbid.not_forbidden', ['%channel%' => $channelName]);

            return;
        }

        $this->auditData = new IrcopAuditData(
            target: $channelName,
        );

        $context->reply('unforbid.success', ['%channel%' => $channelName]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
