<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbRecordWriterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * Writes UDB records to the wire in the UnrealUdb DB command format.
 *
 * Format: :<sid> DB <target> <subcommand> <parameters>
 * - Real-time inserts use target "*" (broadcast) and a trailing value so
 *   multi-word values (topic, forbid, swhois, reason) survive IRC parsing.
 * - Numeric values are prefixed with "*" per the UDB storage format.
 * - The source SID is only prefixed when the active connection knows it.
 */
final readonly class UnrealUdbRecordWriter implements UdbRecordWriterInterface
{
    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function insert(string $block, string $path, string $value): void
    {
        if ('' === $value) {
            return;
        }

        $this->write(sprintf('DB * INS %s::%s :%s', $block, $path, $value));
    }

    public function delete(string $block, string $path): void
    {
        $this->write(sprintf('DB * DEL %s::%s', $block, $path));
    }

    public function requestSync(string $block, string $sourceSid): void
    {
        $this->write(sprintf('DB %s RES %s', $sourceSid, $block));
    }

    public function dropBlock(string $block): void
    {
        $this->write(sprintf('DB * DRP %s', $block));
    }

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $sid = $this->connectionHolder->getServerSid();
        $prefixed = null === $sid ? $line : sprintf(':%s %s', $sid, $line);

        $this->connectionHolder->writeLine($prefixed);
        $this->logger->debug('> ' . $prefixed);
    }
}
