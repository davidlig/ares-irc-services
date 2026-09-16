<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Persistence;

use App\Irc\Adapter\Out\Persistence\DbalServiceNickReservationInventory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalServiceNickReservationInventory::class)]
final class DbalServiceNickReservationInventoryTest extends TestCase
{
    #[Test]
    public function inventoryIsReplacedPerProtocolAndRetainsOriginalNickCasing(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE service_nick_reservations (protocol VARCHAR(32) NOT NULL, nickname_lower VARCHAR(64) NOT NULL, nickname VARCHAR(64) NOT NULL, PRIMARY KEY(protocol, nickname_lower))');
        $inventory = new DbalServiceNickReservationInventory($connection);

        self::assertSame([], $inventory->namesForProtocol('unrealudb'));
        $inventory->replaceForProtocol('unrealudb', ['OperServ', 'NickServ']);
        $inventory->replaceForProtocol('inspircd', ['MemoServ']);
        self::assertSame(['NickServ', 'OperServ'], $inventory->namesForProtocol('unrealudb'));

        $inventory->replaceForProtocol('unrealudb', ['NiCK', 'CHaN']);

        self::assertSame(['CHaN', 'NiCK'], $inventory->namesForProtocol('unrealudb'));
        self::assertSame(['MemoServ'], $inventory->namesForProtocol('inspircd'));
    }

    #[Test]
    public function failedReplacementRollsBackPriorInventory(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE service_nick_reservations (protocol VARCHAR(32) NOT NULL, nickname_lower VARCHAR(64) NOT NULL, nickname VARCHAR(64) NOT NULL, PRIMARY KEY(protocol, nickname_lower))');
        $inventory = new DbalServiceNickReservationInventory($connection);
        $inventory->replaceForProtocol('unreal', ['OldNick']);

        try {
            $inventory->replaceForProtocol('unreal', ['NewNick', 'nEwNiCk']);
            self::fail('A duplicate case-insensitive nickname must fail the replacement.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(['OldNick'], $inventory->namesForProtocol('unreal'));
        }
    }
}
