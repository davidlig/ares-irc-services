<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ProtocolServiceActionsInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * UnrealIRCd: SVS2MODE (+r account), SVSMODE, SVSNICK, KILL.
 */
final readonly class UnrealIRCdProtocolServiceActions implements ProtocolServiceActionsInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void
    {
        $modeDelta = ('0' === $accountName) ? '-r' : '+r';
        $this->write(sprintf(':%s SVS2MODE %s %s', $serverSid, $targetUid, $modeDelta));
    }

    public function setUserMode(string $serverSid, string $targetUid, string $modes): void
    {
        $this->write(sprintf(':%s SVSMODE %s %s', $serverSid, $targetUid, $modes));
    }

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void
    {
        $this->write(sprintf(':%s SVSNICK %s %s %d', $serverSid, $targetUid, $newNick, time()));
    }

    public function killUser(string $serverSid, string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $serverSid, $targetUid, $reason));
    }

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
