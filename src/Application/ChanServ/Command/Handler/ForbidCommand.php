<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function array_slice;
use function implode;
use function trim;

final class ForbidCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChannelForbiddenService $forbiddenService,
    ) {
    }

    public function getName(): string
    {
        return 'FORBID';
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
        return 'forbid.syntax';
    }

    public function getHelpKey(): string
    {
        return 'forbid.help';
    }

    public function getOrder(): int
    {
        return 78;
    }

    public function getShortDescKey(): string
    {
        return 'forbid.short';
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

        $reasonParts = array_slice($context->args, 1);
        $reason = trim(implode(' ', $reasonParts));

        if ('' === $reason) {
            $context->reply('forbid.reason_required');

            return;
        }

        $existing = $this->channelRepository->findByChannelName($channelName);

        if (null !== $existing && $existing->isForbidden()) {
            $this->forbiddenService->forbid($channelName, $reason, $context->sender->nick);
            $this->auditData = new IrcopAuditData(
                target: $channelName,
                reason: $reason,
            );
            $context->reply('forbid.updated', ['%channel%' => $channelName]);

            return;
        }

        $this->forbiddenService->forbid($channelName, $reason, $context->sender->nick);

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            reason: $reason,
        );

        $context->reply('forbid.success', ['%channel%' => $channelName]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
