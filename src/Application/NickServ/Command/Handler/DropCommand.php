<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Irc\Application\Port\In\SenderView;
use Psr\Log\LoggerInterface;

use function assert;
use function sprintf;
use function strcasecmp;
use function strtolower;

final class DropCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
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

    public function getRequiredPermission(): string
    {
        return NickServPermission::DROP;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $resolved = $this->resolveTargetDropAction($context, $sender);
        $action = $resolved['action'];

        return match ($action) {
            'self' => $this->reject($context, 'drop.cannot_drop_self'),
            'not_found' => $this->reject($context, 'drop.not_registered', ['%nickname%' => $resolved['nickname'] ?? '']),
            'pending' => $this->reject($context, 'drop.pending_deletion', ['%nickname%' => $resolved['nickname'] ?? '']),
            'pending_force_noperm', 'force_noperm' => $this->reject($context, 'error.permission_denied'),
            'pending_force_ok', 'force_ok' => $this->executeHardDrop($context, $sender, $resolved),
            'suspended' => $this->reject($context, 'drop.suspended', ['%nickname%' => $resolved['nickname'] ?? '']),
            'forbidden' => $this->reject($context, 'drop.forbidden', ['%nickname%' => $resolved['nickname'] ?? '']),
            'protected' => $this->rejectProtected($context, $resolved['result'] ?? null),
            'soft' => $this->executeSoftDrop($context, $sender, $resolved),
            default => CommandOutcome::rejected(),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function reject(NickServContext $context, string $key, array $params = []): CommandOutcome
    {
        $context->reply($key, $params);

        return CommandOutcome::rejected();
    }

    private function rejectProtected(NickServContext $context, ?NickProtectabilityResult $result): CommandOutcome
    {
        if (null !== $result) {
            $this->replyProtectabilityError($context, $result);
        }

        return CommandOutcome::rejected();
    }

    /**
     * @return array{
     *     action: string,
     *     nickname?: string,
     *     account?: RegisteredNick,
     *     result?: NickProtectabilityResult,
     * }
     */
    private function resolveTargetDropAction(NickServContext $context, SenderView $sender): array
    {
        $targetNick = $context->args[0];

        if (strtolower($targetNick) === strtolower($sender->nick)) {
            return ['action' => 'self'];
        }

        $account = $this->nickRepository->findByNick($targetNick);
        if (null === $account) {
            return ['action' => 'not_found', 'nickname' => $targetNick];
        }

        $force = isset($context->args[1]) && 0 === strcasecmp($context->args[1], 'force');

        return $this->resolveAccountDropAction($context, $targetNick, $account, $force);
    }

    /**
     * @return array{
     *     action: string,
     *     nickname: string,
     *     account?: RegisteredNick,
     *     result?: NickProtectabilityResult,
     * }
     */
    private function resolveAccountDropAction(NickServContext $context, string $targetNick, RegisteredNick $account, bool $force): array
    {
        $result = ['action' => 'soft', 'nickname' => $targetNick, 'account' => $account];

        if ($account->isPendingDeletion()) {
            $result['action'] = $force ? $this->resolvePendingForceDrop($context) : 'pending';
        } elseif ($account->isSuspended()) {
            $result['action'] = 'suspended';
        } elseif ($account->isForbidden()) {
            $result['action'] = 'forbidden';
        } else {
            $protectResult = $this->targetValidator->validate($targetNick);
            if (!$protectResult->isAllowed()) {
                return ['action' => 'protected', 'nickname' => $targetNick, 'result' => $protectResult];
            }

            if ($force) {
                $result['action'] = $this->resolveForceDrop($context);
            }
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

    /**
     * @param array{nickname?: string, account?: RegisteredNick, ...} $resolved
     */
    private function executeHardDrop(NickServContext $context, SenderView $sender, array $resolved): CommandOutcome
    {
        $account = $resolved['account'] ?? null;
        $nickname = $resolved['nickname'] ?? '';
        assert(null !== $account);

        $this->logger->info(sprintf('NickServ DROP: %s hard-dropped by %s', $nickname, $sender->nick));
        $this->dropService->hardDropNick($account, 'manual-force', $sender->nick);
        $context->reply('drop.force_success', ['%nickname%' => $nickname]);

        return CommandOutcome::success(new IrcopAuditData(target: $nickname, extra: ['force' => true]));
    }

    /**
     * @param array{nickname?: string, account?: RegisteredNick, ...} $resolved
     */
    private function executeSoftDrop(NickServContext $context, SenderView $sender, array $resolved): CommandOutcome
    {
        $account = $resolved['account'] ?? null;
        $nickname = $resolved['nickname'] ?? '';
        assert(null !== $account);

        $this->logger->info(sprintf('NickServ DROP: %s soft-dropped by %s', $nickname, $sender->nick));
        $this->dropService->softDropNick($account, $sender->nick);
        $context->reply('drop.success', ['%nickname%' => $nickname]);

        return CommandOutcome::success(new IrcopAuditData(target: $nickname));
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        $nickname = $result->nickname;

        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('drop.cannot_drop_root', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('drop.cannot_drop_oper', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::IsService => $context->reply('drop.cannot_drop_service', ['%nickname%' => $nickname]),
            NickProtectabilityStatus::Allowed => null,
        };
    }
}
