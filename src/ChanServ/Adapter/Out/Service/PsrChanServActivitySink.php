<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Service;

use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use Psr\Log\LoggerInterface;

final readonly class PsrChanServActivitySink implements ChanServActivitySink
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
