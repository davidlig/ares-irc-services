<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Userip\GetUserip;
use App\NickServ\Application\UseCase\Userip\GetUseripHandlerInterface;
use App\NickServ\Application\UseCase\Userip\GetUseripOutcome;
use App\NickServ\Application\UseCase\Userip\GetUseripResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function base64_decode;
use function bin2hex;
use function inet_ntop;
use function strlen;

final readonly class UseripCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private GetUseripHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
        private Clock $clock,
    ) {}

    public function getName(): string
    {
        return 'USERIP';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('userip.syntax', [new Argument('nickname')]);
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
        return 'userip.help';
    }

    public function getExampleKey(): string
    {
        return 'userip.example';
    }

    public function getOrder(): int
    {
        return 60;
    }

    public function getShortDescKey(): string
    {
        return 'userip.short';
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
        return NickServPermission::USERIP;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $target = $this->userLookup->findByNick($targetNick);

        $ip = null !== $target ? $this->decodeIp($target->ipBase64) : null;
        $host = $target?->hostname;

        $result = $this->handler->handle(new GetUserip(
            nickname: $targetNick,
            ip: $ip,
            hostname: $host,
            actorNickname: $context->sender->nick,
            occurredAt: $this->clock->now(),
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, GetUseripResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case GetUseripOutcome::NotOnline:
                $context->replyMissingNick('error.nick_not_online', $result->nickname ?? '');

                return CommandOutcome::rejected();

            case GetUseripOutcome::Success:
                $auditData = new IrcopAuditData(
                    target: $result->nickname ?? '',
                    targetHost: $result->host ?? '',
                    targetIp: $result->ip ?? '',
                );

                $context->reply('userip.result', [
                    '%nickname%' => new Fragment($result->nickname ?? '', Component::Emphasis),
                    '%ip%' => new Fragment($result->ip ?? '', Component::Status),
                    '%host%' => new Fragment($result->host ?? '', Component::Status),
                ], Role::Information);

                return CommandOutcome::success($auditData);
        }
    }

    private function decodeIp(string $ipBase64): string
    {
        $decoded = base64_decode($ipBase64, true);

        if (false === $decoded) {
            return $ipBase64;
        }

        $len = strlen($decoded);

        if (4 === $len) {
            $ip = inet_ntop($decoded);

            return false !== $ip ? $ip : $ipBase64;
        }

        return 16 === $len ? bin2hex($decoded) : $ipBase64;
    }
}
