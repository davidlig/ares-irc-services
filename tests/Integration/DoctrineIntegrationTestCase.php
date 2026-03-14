<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for Doctrine integration tests using SQLite in-memory.
 */
abstract class DoctrineIntegrationTestCase extends TestCase
{
    protected EntityManager $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = $this->createInMemoryEntityManager();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();

        parent::tearDown();
    }

    private function createInMemoryEntityManager(): EntityManager
    {
        $config = new Configuration();

        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../config/doctrine' => 'App\Domain',
        ]));

        $config->setProxyDir(__DIR__ . '/../../var/cache/test/Proxies');
        $config->setProxyNamespace('App\Tests\Proxies');
        $config->setAutoGenerateProxyClasses(true);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        return new EntityManager($connection, $config);
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    protected function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
