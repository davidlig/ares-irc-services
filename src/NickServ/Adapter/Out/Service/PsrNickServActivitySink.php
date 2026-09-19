<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Service;

use App\NickServ\Application\Port\Out\NickServActivitySink;
use Psr\Log\LoggerInterface;

final readonly class PsrNickServActivitySink implements NickServActivitySink
{
    public function __construct(private LoggerInterface $logger) {}

    public function debug(string $message): void
    {
        $this->logger->debug($message);
    }

    public function info(string $message): void
    {
        $this->logger->info($message);
    }

    public function warning(string $message): void
    {
        $this->logger->warning($message);
    }
}
