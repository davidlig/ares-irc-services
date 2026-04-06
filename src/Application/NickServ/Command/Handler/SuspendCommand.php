<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function sprintf;
use function strtolower;
use function trim;

final class SuspendCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
        private readonly NickSuspensionService $suspensionService,
        private readonly EventDispatcherInterface $eventDispatcher,
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

    public function getHelpParams(): array
    {
        return [];
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

        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

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

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickSuspendedEvent(
            nickId: $account->getId(),
            nickname: $targetNick,
            reason: $reason,
            duration: '0' === strtolower($durationStr) ? null : $durationStr,
            expiresAt: $expiresAt,
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

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

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('suspend.cannot_suspend_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('suspend.cannot_suspend_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('suspend.cannot_suspend_service', ['%nickname%' => $nickname]),
        };
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

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
