<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\Port\EventBusInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Shared\Application\Time\RelativeExpiryParser;
use DateTimeImmutable;

use function array_slice;
use function assert;
use function sprintf;
use function strtolower;

final class SuspendCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
        private readonly NickSuspensionService $suspensionService,
        private readonly EventBusInterface $eventDispatcher,
    ) {}

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
        return 67;
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
        return NickServPermission::SUSPEND;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $reasonParts = array_slice($context->args, 2);
        $reason = trim(implode(' ', $reasonParts));

        if ('' === $reason) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        return $this->doSuspend($context, $reason);
    }

    private function doSuspend(NickServContext $context, string $reason): CommandOutcome
    {
        $targetNick = $context->args[0];
        $durationStr = $context->args[1];

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('suspend.not_registered', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        if ($account->isForbidden()) {
            $context->reply('suspend.forbidden', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        if ($account->isSuspended()) {
            $context->reply('suspend.already_suspended', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        return $this->validateAndSuspend($context, $account, $targetNick, $durationStr, $reason);
    }

    private function validateAndSuspend(
        NickServContext $context,
        RegisteredNick $account,
        string $targetNick,
        string $durationStr,
        string $reason,
    ): CommandOutcome {
        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return CommandOutcome::rejected();
        }

        $expiresAt = RelativeExpiryParser::parse($durationStr);

        if (null === $expiresAt && !RelativeExpiryParser::isPermanent($durationStr)) {
            $context->reply('suspend.invalid_duration');

            return CommandOutcome::rejected();
        }

        return $this->finalizeSuspend($context, $account, $targetNick, $durationStr, $reason, $expiresAt);
    }

    private function finalizeSuspend(
        NickServContext $context,
        RegisteredNick $account,
        string $targetNick,
        string $durationStr,
        string $reason,
        ?DateTimeImmutable $expiresAt,
    ): CommandOutcome {
        $account->suspend($reason, $expiresAt);
        $this->nickRepository->save($account);

        $this->suspensionService->enforceSuspension($account);

        $sender = $context->sender;
        assert(null !== $sender);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickSuspendedEvent(
            nickId: $account->getId(),
            nickname: $targetNick,
            reason: $reason,
            duration: '0' === strtolower($durationStr) ? null : $durationStr,
            expiresAt: $expiresAt,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $durationDisplay = null === $expiresAt
            ? $context->trans('suspend.permanent')
            : $context->formatDate($expiresAt);

        $auditData = new IrcopAuditData(
            target: $targetNick,
            reason: $reason,
            extra: ['duration' => $durationStr],
        );

        $context->reply('suspend.success', [
            '%nickname%' => $targetNick,
            '%duration%' => $durationDisplay,
        ]);

        return CommandOutcome::success($auditData);
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::Allowed => null,
            NickProtectabilityStatus::IsRoot => $context->reply('suspend.cannot_suspend_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('suspend.cannot_suspend_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('suspend.cannot_suspend_service', ['%nickname%' => $nickname]),
        };
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
