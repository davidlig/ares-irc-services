<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ServiceNickReservationInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * UnrealIRCd nick reservation via SQLINE command.
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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function reserveNick(ConnectionInterface $connection, string $serverSid, string $nick, string $reason): void
    {
        $line = sprintf(':%s SQLINE %s :%s', $serverSid, $nick, $reason);

        $connection->writeLine($line);
        $this->logger->info('Reserved service nick via SQLINE', ['nick' => $nick, 'serverSid' => $serverSid]);
    }
}
