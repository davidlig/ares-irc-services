<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRegistrationPolicyRepository;
use App\NickServ\Domain\Entity\RegistrationPolicy;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversClass(DoctrineRegistrationPolicyRepository::class)]
final class DoctrineRegistrationPolicyRepositoryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function missingPolicyDefaultsToTokenAndSurvivesRepositoryRestart(): void
    {
        $repository = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Token, $repository->current());
        self::assertSame(RegistrationMode::Token, $repository->change(RegistrationMode::Token));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM nickserv_registration_policy'));
        self::assertSame(RegistrationMode::Token, $repository->change(RegistrationMode::Email));
        $this->entityManager->clear();
        $restarted = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Email, $restarted->current());
        self::assertSame(RegistrationMode::Email, $restarted->change(RegistrationMode::Email));
        self::assertSame(RegistrationMode::Email, $restarted->change(RegistrationMode::Token));
        self::assertSame(RegistrationMode::Token, $repository->current());
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM nickserv_registration_policy'));
    }

    #[Test]
    public function mappingRoundTripAndLiveReadsIgnoreIdentityMap(): void
    {
        $policy = new RegistrationPolicy(RegistrationMode::Email);
        $this->entityManager->persist($policy);
        $this->flushAndClear();
        $found = $this->entityManager->find(RegistrationPolicy::class, 1);
        self::assertNotNull($found);
        self::assertSame(RegistrationMode::Email, $found->getMode());
        $repository = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        $repository->change(RegistrationMode::Token);
        self::assertSame(RegistrationMode::Token, $repository->current());
        $this->entityManager->refresh($found);
        self::assertSame(RegistrationMode::Token, $found->getMode());
    }

    #[Test]
    public function concurrentSingletonCreationRetriesUsingLiveState(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(false, 'EMAIL');
        $driverError = new class('duplicate', '23505') extends AbstractException {};
        $connection->expects(self::once())->method('insert')->willThrowException(new UniqueConstraintViolationException($driverError, null));
        $connection->expects(self::never())->method('executeStatement');
        self::assertSame(RegistrationMode::Email, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Email));
    }

    #[Test]
    public function concurrentChangeRetriesWithoutReportingUnappliedChange(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls('TOKEN', 'EMAIL');
        $connection->expects(self::once())->method('executeStatement')->with('UPDATE nickserv_registration_policy SET mode = ? WHERE id = 1 AND mode = ?', ['EMAIL', 'TOKEN'])->willReturn(0);
        self::assertSame(RegistrationMode::Email, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Email));
    }

    #[Test]
    public function boundedContentionFailsRatherThanInventingSuccess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))->method('fetchOne')->willReturn('TOKEN');
        $connection->expects(self::exactly(3))->method('executeStatement')->willReturn(0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registration policy changed concurrently; retry the command.');
        new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Email);
    }
}
