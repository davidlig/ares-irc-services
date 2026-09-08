<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function max;
use function strpos;
use function substr;
use function time;

final readonly class ActiveConnectionGlineNetworkActions implements GlineNetworkActions
{
    public function __construct(
        private ActiveConnectionHolderInterface $connection,
        private LoggerInterface $logger,
    ) {}

    public function add(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void
    {
        $module = $this->connection->getProtocolModule();
        $serverSid = $this->connection->getServerSid();
        if (null === $module || null === $serverSid) {
            $this->logger->error('GLINE: no active protocol module or server SID');

            return;
        }

        $parts = $this->parts($mask);
        $module->getServiceActions()->addGline(
            $serverSid,
            $parts['user'],
            $parts['host'],
            null === $expiresAt ? 0 : max(0, $expiresAt->getTimestamp() - time()),
            $reason,
        );
    }

    public function remove(string $mask): void
    {
        $module = $this->connection->getProtocolModule();
        $serverSid = $this->connection->getServerSid();
        if (null === $module || null === $serverSid) {
            $this->logger->error('GLINE DEL: no active protocol module or server SID');

            return;
        }

        $parts = $this->parts($mask);
        $module->getServiceActions()->removeGline($serverSid, $parts['user'], $parts['host']);
    }

    /** @return array{user: string, host: string} */
    private function parts(string $mask): array
    {
        $at = strpos($mask, '@');
        if (false === $at) {
            return ['user' => '*', 'host' => $mask];
        }

        $user = substr($mask, 0, $at);
        $host = substr($mask, $at + 1);

        return ['user' => '' === $user ? '*' : $user, 'host' => '' === $host ? '*' : $host];
    }
}
