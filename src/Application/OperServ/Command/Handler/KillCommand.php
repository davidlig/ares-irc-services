<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;

use function array_slice;
use function implode;
use function sprintf;
use function strtolower;

final class KillCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly NetworkUserLookupPort $userLookup,
        private readonly RootUserRegistry $rootRegistry,
        private readonly OperIrcopRepositoryInterface $ircopRepo,
        private readonly RegisteredNickRepositoryInterface $nickRepo,
        private readonly IrcopAccessHelper $accessHelper,
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'KILL';
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
        return 'kill.syntax';
    }

    public function getHelpKey(): string
    {
        return 'kill.help';
    }

    public function getOrder(): int
    {
        return 10;
    }

    public function getShortDescKey(): string
    {
        return 'kill.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return OperServPermission::KILL;
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    public function execute(OperServContext $context): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        $targetNick = $context->args[0];
        $target = $this->userLookup->findByNick($targetNick);
        $targetError = $this->validateTarget($context, $target, $targetNick);

        if (null !== $targetError || null === $target) {
            return;
        }

        $reason = implode(' ', array_slice($context->args, 1));
        $operatorNick = $sender->nick;
        $killReason = sprintf('Killed (%s: %s): %s', $context->getBotName(), $operatorNick, $reason);

        $module = $this->connectionHolder->getProtocolModule();
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $module || null === $serverSid) {
            $this->logger->error('KILL: no active protocol module or server SID', [
                'operator' => $operatorNick,
                'target' => $targetNick,
            ]);

            return;
        }

        $module->getServiceActions()->killUser($serverSid, $target->uid, $killReason);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            targetHost: $target->ident . '@' . $target->hostname,
            targetIp: $target->ipBase64,
            reason: $reason,
        );

        $context->reply('kill.done', [
            '%nickname%' => $targetNick,
            '%reason%' => $reason,
        ]);
    }

    private function validateTarget(OperServContext $context, ?\App\Application\Port\SenderView $target, string $targetNick): ?string
    {
        if (null === $target) {
            $context->reply('kill.user_not_online', ['%nickname%' => $targetNick]);

            return 'not_online';
        }

        $targetNickLower = strtolower($targetNick);
        $errorKey = $this->rootRegistry->isRoot($targetNickLower) ? 'root' : ($this->isOper($target, $targetNickLower) ? 'ircop' : null);

        if (null !== $errorKey) {
            $context->reply('root' === $errorKey ? 'kill.protected_root' : 'kill.protected_ircop', ['%nickname%' => $targetNick]);
        }

        return $errorKey;
    }

    private function isOper(\App\Application\Port\SenderView $target, string $targetNickLower): bool
    {
        if (!$target->isOper || !$target->isIdentified) {
            return false;
        }

        $registeredNick = $this->nickRepo->findByNick($targetNickLower);

        return null !== $registeredNick && null !== $this->ircopRepo->findByNickId($registeredNick->getId());
    }
}
