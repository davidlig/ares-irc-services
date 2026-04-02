<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateInterval;
use DateTimeImmutable;

use function array_slice;
use function strtolower;
use function trim;

final class SuspendCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
        private readonly RootUserRegistry $rootRegistry,
        private readonly NickSuspensionService $suspensionService,
    ) {
    }

    public function getName(): string
    {
        return 'SUSPEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'suspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'suspend.help';
    }

    public function getOrder(): int
    {
        return 70;
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

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::SUSPEND;
    }

    public function execute(NickServContext $context): void
    {
        $targetNick = $context->args[0];
        $durationStr = $context->args[1];
        $reasonParts = array_slice($context->args, 2);
        $reason = trim(implode(' ', $reasonParts));

        if ('' === $reason) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('suspend.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('suspend.forbidden', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('suspend.already_suspended', ['%nickname%' => $targetNick]);

            return;
        }

        $targetNickLower = strtolower($targetNick);

        if ($this->rootRegistry->isRoot($targetNickLower)) {
            $context->reply('suspend.cannot_suspend_root', ['%nickname%' => $targetNick]);

            return;
        }

        $ircop = $this->ircopRepository->findByNickId($account->getId());

        if (null !== $ircop) {
            $context->reply('suspend.cannot_suspend_oper', ['%nickname%' => $targetNick]);

            return;
        }

        $expiresAt = $this->parseExpiry($durationStr);

        if (null === $expiresAt && '0' !== strtolower($durationStr)) {
            $context->reply('suspend.invalid_duration');

            return;
        }

        $account->suspend($reason, $expiresAt);
        $this->nickRepository->save($account);

        $this->suspensionService->enforceSuspension($account);

        $durationDisplay = null === $expiresAt
            ? $context->trans('suspend.permanent')
            : $context->formatDate($expiresAt);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            reason: $reason,
            extra: ['duration' => $durationStr],
        );

        $context->reply('suspend.success', [
            '%nickname%' => $targetNick,
            '%duration%' => $durationDisplay,
        ]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    private function parseExpiry(string $expiryStr): ?DateTimeImmutable
    {
        $expiryStr = strtolower(trim($expiryStr));

        if ('0' === $expiryStr) {
            return null;
        }

        $matches = [];

        if (!preg_match('/^(\d+)([dhm])$/', $expiryStr, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];
        $intervalSpec = match ($unit) {
            'd' => "P{$value}D",
            'h' => "PT{$value}H",
            'm' => "PT{$value}M",
        };

        return (new DateTimeImmutable())->add(new DateInterval($intervalSpec));
    }
}
