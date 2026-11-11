<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\NickAccountBatchDoctrineQuery;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use UnexpectedValueException;

use function array_keys;

#[CoversClass(NickAccountBatchDoctrineQuery::class)]
final class NickAccountBatchDoctrineQueryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function malformedScalarAccountDataIsRejected(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['id' => null, 'nickname' => 'Alice', 'nickname_lower' => 'alice', 'status' => 'registered', 'language' => 'en', 'timezone' => null, 'email' => null]]);
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $this->expectException(UnexpectedValueException::class);
        new NickAccountBatchDoctrineQuery($em)->findAccountsByNicks(['Alice']);
    }

    #[Test]
    public function invalidAccountStatusIsRejected(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->insert('registered_nicks', ['id' => 3, 'nickname' => 'Bad', 'nickname_lower' => 'bad', 'status' => 'corrupt', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0, 'no_expire' => 0]);
        $this->expectException(UnexpectedValueException::class);
        new NickAccountBatchDoctrineQuery($this->entityManager)->findAccountsByNicks(['Bad']);
    }

    #[Test]
    public function accountBatchReturnsScalarViewsWithoutHydratingEntities(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->insert('registered_nicks', ['id' => 1, 'nickname' => 'Alice', 'nickname_lower' => 'alice', 'status' => 'registered', 'language' => 'es', 'timezone' => null, 'email' => 'alice@example.test', 'private' => 0, 'msg_privmsg' => 0, 'no_expire' => 0]);
        $connection->insert('registered_nicks', ['id' => 2, 'nickname' => 'Bob', 'nickname_lower' => 'bob', 'status' => 'suspended', 'language' => 'en', 'timezone' => 'Europe/Madrid', 'email' => null, 'private' => 0, 'msg_privmsg' => 0, 'no_expire' => 0]);
        $query = new NickAccountBatchDoctrineQuery($this->entityManager);
        self::assertSame([], $query->findAccountsByNicks([]));
        $result = $query->findAccountsByNicks(['ALICE', 'bob', 'missing', 'alice']);
        self::assertSame(['alice', 'bob'], array_keys($result));
        self::assertSame(1, $result['alice']->id);
        self::assertTrue($result['alice']->registered);
        self::assertSame('UTC', $result['alice']->timezone);
        self::assertSame('alice@example.test', $result['alice']->email);
        self::assertFalse($result['bob']->registered);
        self::assertTrue($result['bob']->suspended);
        self::assertSame('Europe/Madrid', $result['bob']->timezone);
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap()[RegisteredNick::class] ?? []);
    }
}
