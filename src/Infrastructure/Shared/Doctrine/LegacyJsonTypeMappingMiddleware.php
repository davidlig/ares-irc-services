<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\DBAL\Types\Types;

/**
 * Registers the legacy "json" database-type mapping on SQLite platforms.
 *
 * Databases provisioned by older DBAL versions contain SQLite columns whose
 * declared type is JSON (modern DBAL creates them as CLOB). Any schema
 * introspection — including doctrine:migrations:migrate — fails with
 * "Unknown database type json requested" on those databases. Registering the
 * missing DB type -> Doctrine type mapping restores compatibility for both
 * migrations and runtime queries without touching the data.
 */
final readonly class LegacyJsonTypeMappingMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $platform = parent::getDatabasePlatform($versionProvider);

                if ($platform instanceof SQLitePlatform) {
                    $platform->registerDoctrineTypeMapping('json', Types::JSON);
                }

                return $platform;
            }
        };
    }
}
