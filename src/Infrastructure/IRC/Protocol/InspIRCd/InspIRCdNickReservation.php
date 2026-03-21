<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ServiceNickReservationInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * InspIRCd nick reservation via QLINE command.
 *
 * QLINE prevents regular users from using a nickname while allowing
 * U-lined servers (like services) to introduce it.
 *
 * Format: :serverSid QLINE nick duration :reason
 * Example: :001 QLINE NickServ 0 :Reserved for network services
 *
 * Duration 0 = permanent (no expiry)
 */
final readonly class InspIRCdNickReservation implements ServiceNickReservationInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function reserveNick(ConnectionInterface $connection, string $serverSid, string $nick, string $reason): void
    {
        $line = sprintf(':%s QLINE %s 0 :%s', $serverSid, $nick, $reason);

        $connection->writeLine($line);
        $this->logger->info('Reserved service nick via QLINE', ['nick' => $nick, 'serverSid' => $serverSid]);
    }
}
