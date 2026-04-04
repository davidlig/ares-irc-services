<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickForceService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\DebugActionPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function strtolower;

final class DropCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
        private readonly RootUserRegistry $rootRegistry,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly NickForceService $forceService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DebugActionPort $debug,
        private readonly LoggerInterface $logger,
        private readonly string $guestPrefix = 'Guest-',
    ) {
    }

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
        return 75;
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
        return ['%prefix%' => $this->guestPrefix];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];
        $targetNickLower = strtolower($targetNick);

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('drop.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isSuspended()) {
            $context->reply('drop.suspended', ['%nickname%' => $targetNick]);

            return;
        }

        if ($account->isForbidden()) {
            $context->reply('drop.forbidden', ['%nickname%' => $targetNick]);

            return;
        }

        if ($this->rootRegistry->isRoot($targetNickLower)) {
            $context->reply('drop.cannot_drop_root', ['%nickname%' => $targetNick]);

            return;
        }

        $ircop = $this->ircopRepository->findByNickId($account->getId());

        if (null !== $ircop) {
            $context->reply('drop.cannot_drop_oper', ['%nickname%' => $targetNick]);

            return;
        }

        $senderNickLower = strtolower($context->sender->nick);

        if ($targetNickLower === $senderNickLower) {
            $context->reply('drop.cannot_drop_self');

            return;
        }

        $onlineUser = $this->userLookup->findByNick($targetNick);

        if (null !== $onlineUser) {
            $this->forceService->forceGuestNick($onlineUser->uid, null, 'ircop-drop');
        }

        $this->eventDispatcher->dispatch(new NickDropEvent(
            $account->getId(),
            $account->getNickname(),
            $account->getNicknameLower(),
            'manual',
        ));

        $this->nickRepository->delete($account);

        $this->debug->log(
            operator: $context->sender->nick,
            command: 'DROP',
            target: $targetNick,
            reason: 'manual drop',
        );

        $this->logger->info('Nickname dropped via DROP command', [
            'operator' => $context->sender->nick,
            'nickname' => $targetNick,
            'was_online' => null !== $onlineUser,
        ]);

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            reason: 'manual drop',
            extra: ['was_online' => null !== $onlineUser],
        );

        $context->reply('drop.success', ['%nickname%' => $targetNick]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
