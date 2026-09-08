<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\Application\OperServ\Security\OperServPermission;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\UseCase\Kill\KillNetworkUser;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserHandler;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserOutcome;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserResult;
use DateTimeImmutable;

use function array_slice;
use function implode;

/** IRC parsing and presentation adapter for KILL <nickname> <reason>. */
final readonly class KillCommand implements OperServCommandInterface
{
    public function __construct(
        private KillNetworkUserHandler $handler,
        private CommandAuditRecorder $audit,
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

    public function getRequiredPermission(): string
    {
        return OperServPermission::KILL;
    }

    /** @return array<string, string> */
    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $this->present($context, $this->handler->handle(new KillNetworkUser(
            actorNickname: $context->sender->nick,
            serviceNickname: $context->getBotName(),
            targetNickname: $context->args[0],
            reason: implode(' ', array_slice($context->args, 1)),
            occurredAt: new DateTimeImmutable(),
        )));
    }

    private function present(OperServContext $context, KillNetworkUserResult $result): void
    {
        if (null !== $result->auditRecord) {
            $this->audit->record($result->auditRecord);
        }

        match ($result->outcome) {
            KillNetworkUserOutcome::Killed => $context->reply('kill.done', [
                'nickname' => $result->targetNickname,
                'reason' => $result->reason,
            ]),
            KillNetworkUserOutcome::NotOnline => $context->reply('kill.user_not_online', ['nickname' => $result->targetNickname]),
            KillNetworkUserOutcome::ProtectedRoot => $context->reply('kill.protected_root', ['nickname' => $result->targetNickname]),
            KillNetworkUserOutcome::ProtectedIrcOperator => $context->reply('kill.protected_ircop', ['nickname' => $result->targetNickname]),
            KillNetworkUserOutcome::NetworkUnavailable => null,
        };
    }
}
