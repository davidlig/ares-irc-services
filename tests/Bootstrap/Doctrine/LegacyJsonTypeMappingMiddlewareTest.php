<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Doctrine;

use App\Bootstrap\Doctrine\LegacyJsonTypeMappingMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\ServerVersionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyJsonTypeMappingMiddleware::class)]
final class LegacyJsonTypeMappingMiddlewareTest extends TestCase
{
    #[Test]
    public function sqlitePlatformsLearnTheLegacyJsonMapping(): void
    {
        $middleware = new LegacyJsonTypeMappingMiddleware();
        $platform = $this->platformThroughMiddleware($middleware, new SQLitePlatform());

        self::assertSame('json', $platform->getDoctrineTypeMapping('json'));
    }

    #[Test]
    public function nonSqlitePlatformsArePassedThroughUnmodified(): void
    {
        $middleware = new LegacyJsonTypeMappingMiddleware();
        $platform = $this->platformThroughMiddleware($middleware, new MySQLPlatform());

        self::assertInstanceOf(MySQLPlatform::class, $platform);
        self::assertSame('json', $platform->getDoctrineTypeMapping('json'));
    }

    #[Test]
    public function middlewareIsAppliedToTheConfiguredConnection(): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new LegacyJsonTypeMappingMiddleware()]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        self::assertSame('json', $connection->getDatabasePlatform()->getDoctrineTypeMapping('json'));
    }

    private function platformThroughMiddleware(LegacyJsonTypeMappingMiddleware $middleware, AbstractPlatform $platform): AbstractPlatform
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('getDatabasePlatform')->willReturn($platform);

        return $middleware->wrap($driver)->getDatabasePlatform($this->createStub(ServerVersionProvider::class));
    }
}
