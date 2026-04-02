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
    ) {
    }

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
        $reason = implode(' ', array_slice($context->args, 1));

        $target = $this->userLookup->findByNick($targetNick);
        if (null === $target) {
            $context->reply('kill.user_not_online', ['%nick%' => $targetNick]);

            return;
        }

        $targetNickLower = strtolower($targetNick);

        if ($this->rootRegistry->isRoot($targetNickLower)) {
            $context->reply('kill.protected_root', ['%nick%' => $targetNick]);

            return;
        }

        if ($this->isOper($target, $targetNickLower)) {
            $context->reply('kill.protected_ircop', ['%nick%' => $targetNick]);

            return;
        }

        $operatorNick = $sender->nick;
        $killReason = sprintf('Killed (%s: %s): %s', $context->getBotName(), $operatorNick, $reason);

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->error('KILL: no active protocol module', [
                'operator' => $operatorNick,
                'target' => $targetNick,
            ]);

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            $this->logger->error('KILL: no server SID', [
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
            '%nick%' => $targetNick,
            '%reason%' => $reason,
        ]);
    }

    private function isOper(\App\Application\Port\SenderView $target, string $targetNickLower): bool
    {
        if (!$target->isOper) {
            return false;
        }

        if (!$target->isIdentified) {
            return false;
        }

        $registeredNick = $this->nickRepo->findByNick($targetNickLower);
        if (null === $registeredNick) {
            return false;
        }

        $ircop = $this->ircopRepo->findByNickId($registeredNick->getId());

        return null !== $ircop;
    }
}
