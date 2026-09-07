<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\Security\NickServPermission;

use function sprintf;

final class UnsuspendCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly EventBusInterface $eventDispatcher,
        private readonly Clock $clock,
    ) {}

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
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account) {
            $context->reply('unsuspend.not_registered', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        if (!$account->isSuspended()) {
            $context->reply('unsuspend.not_suspended', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        $account->unsuspend();
        $this->nickRepository->save($account);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickUnsuspendedEvent(
            nickId: $account->getId(),
            nickname: $targetNick,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
            occurredAt: $this->clock->now(),
        ));

        $context->reply('unsuspend.success', ['%nickname%' => $targetNick]);

        return CommandOutcome::success(new IrcopAuditData(target: $targetNick));
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
