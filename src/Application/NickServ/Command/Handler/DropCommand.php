<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

use function strcasecmp;
use function strtolower;

final class DropCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
        private readonly NickDropService $dropService,
        private readonly LoggerInterface $logger,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
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
        return 71;
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

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::DROP;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $resolved = $this->resolveDropAction($context);
        $action = $resolved['action'];

        match ($action) {
            'silent' => null,
            'self' => $context->reply('drop.cannot_drop_self'),
            'not_found' => $context->reply('drop.not_registered', ['%nickname%' => $resolved['nickname']]),
            'pending' => $context->reply('drop.pending_deletion', ['%nickname%' => $resolved['nickname']]),
            'pending_force_noperm', 'force_noperm' => $context->reply('error.permission_denied'),
            'pending_force_ok', 'force_ok' => $this->executeHardDrop($context, $resolved),
            'suspended' => $context->reply('drop.suspended', ['%nickname%' => $resolved['nickname']]),
            'forbidden' => $context->reply('drop.forbidden', ['%nickname%' => $resolved['nickname']]),
            'protected' => $this->replyProtectabilityError($context, $resolved['result']),
            'soft' => $this->executeSoftDrop($context, $resolved),
        };
    }

    private function resolveDropAction(NickServContext $context): array
    {
        if (null === $context->sender) {
            return ['action' => 'silent'];
        }

        return $this->resolveTargetDropAction($context);
    }

    private function resolveTargetDropAction(NickServContext $context): array
    {
        $targetNick = $context->args[0];

        if (strtolower($targetNick) === strtolower($context->sender->nick)) {
            return ['action' => 'self'];
        }

        $account = $this->nickRepository->findByNick($targetNick);
        if (null === $account) {
            return ['action' => 'not_found', 'nickname' => $targetNick];
        }

        $force = isset($context->args[1]) && 0 === strcasecmp($context->args[1], 'force');

        return $this->resolveAccountDropAction($context, $targetNick, $account, $force);
    }

    private function resolveAccountDropAction(NickServContext $context, string $targetNick, \App\Domain\NickServ\Entity\RegisteredNick $account, bool $force): array
    {
        $result = ['action' => 'soft', 'nickname' => $targetNick, 'account' => $account];

        if ($account->isPendingDeletion()) {
            $result['action'] = $force ? $this->resolvePendingForceDrop($context) : 'pending';
        } elseif ($account->isSuspended()) {
            $result['action'] = 'suspended';
        } elseif ($account->isForbidden()) {
            $result['action'] = 'forbidden';
        } elseif (!$this->targetValidator->validate($targetNick)->isAllowed()) {
            $result = ['action' => 'protected', 'result' => $this->targetValidator->validate($targetNick)];
        } elseif ($force) {
            $result['action'] = $this->resolveForceDrop($context);
        }

        return $result;
    }

    private function resolvePendingForceDrop(NickServContext $context): string
    {
        return (null === $this->authorizationChecker || !$this->authorizationChecker->isGranted(NickServPermission::DROP_FORCE, $context))
            ? 'pending_force_noperm'
            : 'pending_force_ok';
    }

    private function resolveForceDrop(NickServContext $context): string
    {
        return (null === $this->authorizationChecker || !$this->authorizationChecker->isGranted(NickServPermission::DROP_FORCE, $context))
            ? 'force_noperm'
            : 'force_ok';
    }

    private function executeHardDrop(NickServContext $context, array $resolved): void
    {
        $this->dropService->hardDropNick($resolved['account'], 'manual-force', $context->sender->nick);
        $this->auditData = new IrcopAuditData(target: $resolved['nickname'], extra: ['force' => true]);
        $context->reply('drop.force_success', ['%nickname%' => $resolved['nickname']]);
    }

    private function executeSoftDrop(NickServContext $context, array $resolved): void
    {
        $this->dropService->softDropNick($resolved['account'], $context->sender->nick);
        $this->auditData = new IrcopAuditData(target: $resolved['nickname']);
        $context->reply('drop.success', ['%nickname%' => $resolved['nickname']]);
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('drop.cannot_drop_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('drop.cannot_drop_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('drop.cannot_drop_service', ['%nickname%' => $nickname]),
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
