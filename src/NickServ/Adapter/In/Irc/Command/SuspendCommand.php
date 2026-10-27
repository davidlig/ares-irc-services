<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Suspend\SuspendNick;
use App\NickServ\Application\UseCase\Suspend\SuspendNickHandler;
use App\NickServ\Application\UseCase\Suspend\SuspendNickOutcome;
use App\NickServ\Application\UseCase\Suspend\SuspendNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;

use function array_slice;
use function base64_decode;
use function implode;
use function inet_ntop;
use function sprintf;
use function trim;

final readonly class SuspendCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private SuspendNickHandler $handler) {}

    public function getName(): string
    {
        return 'SUSPEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('suspend.syntax', [
            new Argument('nickname'),
            new Argument('duration'),
            new Argument('reason', rest: true, nonBlank: true),
        ]);
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
        return 'suspend.help';
    }

    public function getExampleKey(): string
    {
        return 'suspend.example';
    }

    public function getOrder(): int
    {
        return 67;
    }

    public function getShortDescKey(): string
    {
        return 'suspend.short';
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

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->reply('error.syntax', ['syntax' => $context->trans($validation->syntaxKey)]);

            return CommandOutcome::rejected();
        }

        $reason = trim(implode(' ', array_slice($context->args, 2)));

        $result = $this->handler->handle(new SuspendNick(
            $this->actor($context, $sender),
            $context->args[0],
            $context->args[1],
            $reason,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, SuspendNickResult $result): CommandOutcome
    {
        $errorKey = match ($result->outcome) {
            SuspendNickOutcome::NotRegistered => 'error.nick_not_registered',
            SuspendNickOutcome::Forbidden => 'suspend.forbidden',
            SuspendNickOutcome::AlreadySuspended => 'suspend.already_suspended',
            SuspendNickOutcome::TargetIsRoot => 'suspend.cannot_suspend_root',
            SuspendNickOutcome::TargetIsIrcop => 'suspend.cannot_suspend_oper',
            SuspendNickOutcome::TargetIsService => 'suspend.cannot_suspend_service',
            SuspendNickOutcome::InvalidDuration => 'suspend.invalid_duration',
            SuspendNickOutcome::Suspended => null,
        };

        if (null !== $errorKey) {
            $context->reply($errorKey, ['%nickname%' => $result->targetNickname]);

            return CommandOutcome::rejected();
        }

        $duration = null === $result->expiresAt
            ? $context->trans('label.permanent_inline')
            : $context->formatDate($result->expiresAt);
        $context->reply('suspend.success', [
            '%nickname%' => $result->targetNickname,
            '%duration%' => $duration,
        ]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->targetNickname,
            reason: $result->reason,
            extra: ['duration' => $result->duration],
        ));
    }

    private function actor(NickServContext $context, SenderView $sender): NickOperationActor
    {
        return new NickOperationActor(
            $sender->nick,
            $context->senderAccount?->getId(),
            $sender->uid,
            $sender->serverSid,
            sprintf('%s@%s', $sender->ident, $sender->hostname),
            self::decodeIp($sender->ipBase64),
        );
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
