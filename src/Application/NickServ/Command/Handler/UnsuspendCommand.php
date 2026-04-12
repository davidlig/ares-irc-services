<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Domain\NickServ\Event\NickUnsuspendedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

final class UnsuspendCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getName(): string
    {
        return 'UNSUSPEND';
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
        return 'unsuspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unsuspend.help';
    }

    public function getOrder(): int
    {
        return 68;
    }

    public function getShortDescKey(): string
    {
        return 'unsuspend.short';
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

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('unsuspend.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        if (!$account->isSuspended()) {
            $context->reply('unsuspend.not_suspended', ['%nickname%' => $targetNick]);

            return;
        }

        $account->unsuspend();
        $this->nickRepository->save($account);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickUnsuspendedEvent(
            nickId: $account->getId(),
            nickname: $targetNick,
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $this->auditData = new IrcopAuditData(
            target: $targetNick,
        );

        $context->reply('unsuspend.success', ['%nickname%' => $targetNick]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
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
