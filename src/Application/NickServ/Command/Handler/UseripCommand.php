<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\NetworkUserLookupPort;

use function sprintf;
use function strlen;

final readonly class UseripCommand implements NickServCommandInterface
{
    public function __construct(
        private NetworkUserLookupPort $userLookup,
    ) {
    }

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

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::USERIP;
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $targetNick = $context->args[0];
        $target = $this->userLookup->findByNick($targetNick);

        if (null === $target) {
            $context->reply('userip.not_online', ['%nick%' => $targetNick]);

            return;
        }

        $ip = $this->decodeIp($target->ipBase64);
        $host = $target->hostname;

        $context->reply('userip.result', [
            '%nick%' => $targetNick,
            '%ip%' => $ip,
            '%host%' => $host,
        ]);
    }

    private function decodeIp(string $ipBase64): string
    {
        $decoded = base64_decode($ipBase64, true);
        if (false === $decoded) {
            return $ipBase64;
        }

        // IPv4 (4 bytes)
        if (4 === strlen($decoded)) {
            $parts = unpack('C4', $decoded);

            return sprintf('%d.%d.%d.%d', $parts[1], $parts[2], $parts[3], $parts[4]);
        }

        // IPv6 (16 bytes)
        if (16 === strlen($decoded)) {
            return bin2hex($decoded);
        }

        return $ipBase64;
    }
}
