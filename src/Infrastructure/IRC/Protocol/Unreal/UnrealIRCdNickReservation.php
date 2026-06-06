<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ServiceNickReservationInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;
use function time;

/**
 * UnrealIRCd nick reservation via SQLINE/TKL commands.
 *
 * SQLINE prevents regular users from using a nickname while allowing
 * U-lined servers (like services) to introduce it.
 *
 * Format: :serverSid SQLINE nick :reason
 * Example: :001 SQLINE NickServ :Reserved for network services
 */
final readonly class UnrealIRCdNickReservation implements ServiceNickReservationInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function reserveNick(string $nick, string $reason): void
    {
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return;
        }

        $line = sprintf(':%s SQLINE %s :%s', $serverSid, $nick, $reason);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Reserved service nick via SQLINE', ['nick' => $nick, 'serverSid' => $serverSid]);
    }

    public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void
    {
        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return;
        }

        $setAt = time();
        $expiresAt = 0 === $durationSeconds ? 0 : $setAt + $durationSeconds;
        $line = sprintf('TKL + Q * %s %s %d %d :%s', $nick, $serverSid, $expiresAt, $setAt, $reason);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Reserved service nick via TKL Q-line', [
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

        $line = sprintf(':%s UNSQLINE %s', $serverSid, $nick);

        $this->connectionHolder->writeLine($line);
        $this->logger->info('Released service nick via UNSQLINE', ['nick' => $nick, 'serverSid' => $serverSid]);
    }
}
