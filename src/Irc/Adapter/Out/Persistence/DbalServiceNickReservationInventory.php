<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Persistence;

use App\Irc\Adapter\ServiceBridge\ServiceNickReservationInventory;
use Doctrine\DBAL\Connection;

use function strtolower;

final readonly class DbalServiceNickReservationInventory implements ServiceNickReservationInventory
{
    public function __construct(private Connection $connection) {}

    public function namesForProtocol(string $protocol): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT nickname FROM service_nick_reservations WHERE protocol = ? ORDER BY nickname_lower',
            [$protocol],
        );

        return $names;
    }

    public function replaceForProtocol(string $protocol, array $nicknames): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($protocol, $nicknames): void {
            $connection->delete('service_nick_reservations', ['protocol' => $protocol]);

            foreach ($nicknames as $nickname) {
                $connection->insert('service_nick_reservations', [
                    'protocol' => $protocol,
                    'nickname_lower' => strtolower($nickname),
                    'nickname' => $nickname,
                ]);
            }
        });
    }
}
