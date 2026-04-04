<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\ForbiddenNickService;
use Psr\Log\LoggerInterface;

final class UnforbidCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly ForbiddenNickService $forbiddenService,
        private readonly LoggerInterface $logger,
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
        return 73;
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
        return NickServPermission::FORBID;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];

        $success = $this->forbiddenService->unforbid($targetNick);

        if (!$success) {
            $context->reply('unforbid.not_forbidden', ['%nickname%' => $targetNick]);

            return;
        }

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
        );

        $this->logger->info('Nickname unforbidden via UNFORBID command', [
            'operator' => $context->sender->nick,
            'nickname' => $targetNick,
        ]);

        $context->reply('unforbid.success', ['%nickname%' => $targetNick]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
