<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Irc\Application\Port\In\NetworkUserLookupPort;

use function strlen;

final class UseripCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly NetworkUserLookupPort $userLookup,
    ) {}

    public function getName(): string
    {
        return 'USERIP';
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
        return 'userip.syntax';
    }

    public function getHelpKey(): string
    {
        return 'userip.help';
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

        if (null === $target) {
            $context->reply('userip.not_online', ['%nickname%' => $targetNick]);

            return CommandOutcome::rejected();
        }

        $ip = $this->decodeIp($target->ipBase64);
        $host = $target->hostname;

        $auditData = new IrcopAuditData(
            target: $targetNick,
            targetHost: $host,
            targetIp: $ip,
        );

        $context->reply('userip.result', [
            '%nickname%' => $targetNick,
            '%ip%' => $ip,
            '%host%' => $host,
        ]);

        return CommandOutcome::success($auditData);
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
