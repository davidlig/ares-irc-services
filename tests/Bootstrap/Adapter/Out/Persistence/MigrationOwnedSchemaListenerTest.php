<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence;

use App\Bootstrap\Adapter\Out\Persistence\MigrationOwnedSchemaListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationOwnedSchemaListener::class)]
final class MigrationOwnedSchemaListenerTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testOnlyMigrationOwnedNativeAssetsAreProjected(AbstractPlatform $platform): void
    {
        $column = Column::editor()->setUnquotedName('nickname_lower')->setTypeName(Types::STRING)->setLength(32)->create();
        $table = Table::editor()->setUnquotedName('registered_nicks')->addColumn($column)->create();
        $native = $table->edit()->addColumn(Column::editor()->setUnquotedName('list_search_key')->setTypeName(Types::STRING)->setLength(32)->create())
            ->addIndex(Index::editor()->setUnquotedName('idx_registered_nicks_list_pattern')->setType(IndexType::REGULAR)->setUnquotedColumnNames('nickname_lower'))->create();
        $target = Schema::editor()->addTable($table)->create();
        $manager = $this->createStub(AbstractSchemaManager::class);
        $manager->method('listTableNames')->willReturn(['registered_nicks']);
        $manager->method('introspectTable')->willReturn($native);
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $event = new GenerateSchemaEventArgs($em, $target);
        new MigrationOwnedSchemaListener()->postGenerateSchema($event);
        $actual = $event->getSchema()->getTable('registered_nicks');
        self::assertTrue($actual->hasIndex('idx_registered_nicks_list_pattern'));
        self::assertSame($platform instanceof AbstractMySQLPlatform, $actual->hasColumn('list_search_key'));
        self::assertFalse($target->getTable('registered_nicks')->hasIndex('idx_registered_nicks_list_pattern'));
        // Projecting twice cannot overwrite explicit target assets or add duplicate indexes.
        new MigrationOwnedSchemaListener()->postGenerateSchema($event);
        self::assertSame($actual->getColumns(), $event->getSchema()->getTable('registered_nicks')->getColumns());
    }

    #[DataProvider('platforms')]
    public function testCanonicalGlobalSettingsSchemaIsProjectedOnEveryPlatform(AbstractPlatform $platform): void
    {
        $manager = $this->createStub(AbstractSchemaManager::class);
        $manager->method('listTableNames')->willReturn([]);
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $original = new Schema();
        $event = new GenerateSchemaEventArgs($em, $original);
        new MigrationOwnedSchemaListener()->postGenerateSchema($event);
        self::assertFalse($original->hasTable('service_settings'));
        $settings = $event->getSchema()->getTable('service_settings');
        self::assertCount(3, $settings->getColumns());
        foreach (['service_name', 'setting_name', 'setting_value'] as $column) {
            self::assertTrue($settings->hasColumn($column));
        }
        $primary = $settings->getPrimaryKeyConstraint();
        self::assertNotNull($primary);
        self::assertSame(['service_name', 'setting_name'], array_map(static fn ($name): string => $name->toString(), $primary->getColumnNames()));
        self::assertSame(32, $settings->getColumn('service_name')->getLength());
        self::assertSame(64, $settings->getColumn('setting_name')->getLength());
        new MigrationOwnedSchemaListener()->postGenerateSchema($event);
        self::assertSame($settings->getColumns(), $event->getSchema()->getTable('service_settings')->getColumns());
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'sqlite' => [new SQLitePlatform()];
        yield 'postgresql schema only' => [new PostgreSQLPlatform()];
        yield 'mysql schema only' => [new MySQLPlatform()];
        yield 'mariadb schema only' => [new MariaDBPlatform()];
    }
}
