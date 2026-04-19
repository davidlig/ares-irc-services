<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ServiceNickReservationInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * InspIRCd v4 nick reservation via ADDLINE/DELLINE commands.
 *
 * InspIRCd v4 (protocol 1205+) replaced QLINE with ADDLINE/DELLINE:
 * - Reserve: :serverSid ADDLINE Q nick serverSid timestamp duration :reason
 * - Release: :serverSid DELLINE Q nick
 *
 * Duration 0 = permanent (no expiry).
 */
final readonly class InspIRCdNickReservation implements ServiceNickReservationInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function reserveNick(string $nick, string $reason): void
    {
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return;
        }

        $line = sprintf(':%s ADDLINE Q %s %s %d 0 :%s', $serverSid, $nick, $serverSid, time(), $reason);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Reserved service nick via ADDLINE Q', ['nick' => $nick, 'serverSid' => $serverSid]);
    }

    public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void
    {
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return;
        }

        $line = sprintf(':%s ADDLINE Q %s %s %d %d :%s', $serverSid, $nick, $serverSid, time(), $durationSeconds, $reason);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Reserved service nick via ADDLINE Q with duration', [
            'nick' => $nick,
            'serverSid' => $serverSid,
            'duration' => $durationSeconds,
        ]);
    }

    public function releaseNick(string $nick): void
    {
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return;
        }

        $line = sprintf(':%s DELLINE Q %s', $serverSid, $nick);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Released service nick via DELLINE Q', ['nick' => $nick, 'serverSid' => $serverSid]);
    }
}
