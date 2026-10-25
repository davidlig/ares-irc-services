<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineBadmailRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(DoctrineBadmailRepository::class)]
final class DoctrineBadmailRepositoryTest extends TestCase
{
    #[Test]
    public function rejectsInvalidScalarCountAtDriverBoundary(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $this->expectException(UnexpectedValueException::class);
        new DoctrineBadmailRepository($em)->countMatching('*');
    }

    #[Test]
    public function rejectsInvalidRowShapeAtDriverBoundary(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'domain' => null, 'reason' => 'reason']]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $this->expectException(UnexpectedValueException::class);
        new DoctrineBadmailRepository($em)->page('*', 0, 50);
    }
}
