<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Unsuspend\UnsuspendNick;
use App\NickServ\Application\UseCase\Unsuspend\UnsuspendNickHandler;
use App\NickServ\Application\UseCase\Unsuspend\UnsuspendNickOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

use function sprintf;

final readonly class UnsuspendCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private UnsuspendNickHandler $handler) {}

    public function getName(): string
    {
        return 'UNSUSPEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('unsuspend.syntax', [new Argument('nickname')]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'unsuspend.help';
    }

    public function getExampleKey(): string
    {
        return 'unsuspend.example';
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

        $result = $this->handler->handle(new UnsuspendNick(
            new NickOperationActor(
                $sender->nick,
                $context->senderAccount?->getId(),
                $sender->uid,
                $sender->serverSid,
                sprintf('%s@%s', $sender->ident, $sender->hostname),
                self::decodeIp($sender->ipBase64),
            ),
            $context->args[0],
        ));

        $errorKey = match ($result->outcome) {
            UnsuspendNickOutcome::NotRegistered => 'error.nick_not_registered',
            UnsuspendNickOutcome::NotSuspended => 'unsuspend.not_suspended',
            UnsuspendNickOutcome::Unsuspended => null,
        };
        if (null !== $errorKey) {
            $context->reply($errorKey, ['%nickname%' => $result->targetNickname]);

            return CommandOutcome::rejected();
        }

        $context->reply('unsuspend.success', ['%nickname%' => $result->targetNickname]);

        return CommandOutcome::success(new IrcopAuditData(target: $result->targetNickname));
    }

    private static function decodeIp(string $ipBase64): string
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
