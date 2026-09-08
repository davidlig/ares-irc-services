<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\UseCase\Global\SendGlobalMessage;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageHandler;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageOutcome;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageResult;
use DateTimeImmutable;

use function array_slice;
use function implode;

/** IRC parsing and presentation adapter for GLOBAL <mask|service> <NOTICE|PRIVMSG> <message>. */
final readonly class GlobalCommand implements OperServCommandInterface
{
    public function __construct(
        private SendGlobalMessageHandler $handler,
        private CommandAuditRecorder $audit,
    ) {}

    public function getName(): string
    {
        return 'GLOBAL';
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
        return 'global.syntax';
    }

    public function getHelpKey(): string
    {
        return 'global.help';
    }

    public function getOrder(): int
    {
        return 35;
    }

    public function getShortDescKey(): string
    {
        return 'global.short';
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
        return OperServPermission::GLOBAL;
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

        $this->present($context, $this->handler->handle(new SendGlobalMessage(
            actorNickname: $context->sender->nick,
            senderMaskOrServiceNickname: $context->args[0],
            messageType: $context->args[1],
            message: implode(' ', array_slice($context->args, 2)),
            occurredAt: new DateTimeImmutable(),
        )));
    }

    private function present(OperServContext $context, SendGlobalMessageResult $result): void
    {
        if (null !== $result->auditRecord) {
            $this->audit->record($result->auditRecord);
        }

        match ($result->outcome) {
            SendGlobalMessageOutcome::Sent => $context->reply('global.done', [
                'nickname' => $result->senderNickname,
                'count' => (string) $result->recipientCount,
            ]),
            SendGlobalMessageOutcome::InvalidMessageType => $context->reply('global.type_invalid'),
            SendGlobalMessageOutcome::InvalidMask => $context->reply('global.mask_invalid', ['error' => $result->maskError]),
            SendGlobalMessageOutcome::NicknameConnected => $context->reply('global.nick_connected', ['nickname' => $result->senderNickname]),
            SendGlobalMessageOutcome::NicknameRegistered => $context->reply('global.nick_registered', ['nickname' => $result->senderNickname]),
            SendGlobalMessageOutcome::NetworkUnavailable => null,
        };
    }
}
