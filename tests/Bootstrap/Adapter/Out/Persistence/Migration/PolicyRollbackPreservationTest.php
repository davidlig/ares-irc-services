<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function basename;
use function dirname;
use function glob;
use function is_a;

#[CoversNothing]
final class PolicyRollbackPreservationTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function policies(): iterable
    {
        yield 'ircop' => ['20260926000001', 'ircop_only'];
        yield 'private' => ['20260926000002', 'private'];
    }

    #[Test]
    #[DataProvider('policies')]
    public function isolatedRollbackPreservesLaterColumnsValuesAndListIndex(string $version, string $owned): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($db));
            $factory->getMetadataStorage()->ensureInitialized();
            foreach (glob(dirname(__DIR__, 6) . '/migrations/Version*.php') ?: [] as $path) {
                $class = 'App\\Migrations\\' . basename($path, '.php');
                self::assertTrue(is_a($class, AbstractMigration::class, true));
                $migration = $factory->getMigrationFactory()->createVersion($class);
                $plan = new MigrationPlan(new Version($class), $migration, 'up');
                $factory->getMigrator()->migrate(new MigrationPlanList([$plan], 'up'), new MigratorConfiguration());
            }
            $db->insert('registered_channels', ['name' => '#preserve', 'name_lower' => '#preserve', 'founder_nick_id' => 1, 'description' => '', 'entrymsg' => '', 'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 0, 'created_at' => '2026-10-28 00:00:00', 'registration_restricted_until' => '2030-01-01 00:00:00', 'ircop_only' => 1, 'private' => 1]);
            $before = $db->fetchAllAssociative('SELECT * FROM registered_channels');
            unset($before[0][$owned]);
            $indexes = $db->fetchAllAssociative("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'registered_channels' ORDER BY name");
            $class = 'App\\Migrations\\Version' . $version;
            self::assertTrue(is_a($class, AbstractMigration::class, true));
            $metadata = $db->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');
            $original = $db->fetchAllAssociative('SELECT * FROM registered_channels');
            foreach ([true, false] as $dryRun) {
                $migration = $factory->getMigrationFactory()->createVersion($class);
                $plan = new MigrationPlan(new Version($class), $migration, 'down');
                $factory->getMigrator()->migrate(new MigrationPlanList([$plan], 'down'), new MigratorConfiguration()->setDryRun($dryRun));
                if ($dryRun) {
                    self::assertSame($metadata, $db->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version'));
                    self::assertSame($original, $db->fetchAllAssociative('SELECT * FROM registered_channels'));
                }
            }
            self::assertSame($before, $db->fetchAllAssociative('SELECT * FROM registered_channels'));
            self::assertSame($indexes, $db->fetchAllAssociative("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'registered_channels' ORDER BY name"));
        } finally {
            $db->close();
        }
    }
}
