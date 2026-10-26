<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\DoctrineRegistrationPolicyRepository;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
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
    public function readsLiveSingletonAndPersistsModeAcrossRepositoryInstances(): void
    {
        $repository = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Direct, $repository->current());
        self::assertSame(RegistrationMode::Direct, $repository->change(RegistrationMode::Direct));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM chanserv_registration_policy'));
        self::assertSame(RegistrationMode::Direct, $repository->change(RegistrationMode::Approval));
        self::assertSame(RegistrationMode::Approval, new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection())->current());
        self::assertSame(RegistrationMode::Approval, $repository->change(RegistrationMode::Approval));
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM chanserv_registration_policy'));
    }

    #[Test]
    public function retriesConcurrentSingletonCreationUsingTheWinningLiveMode(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(false, 'APPROVAL');
        $driverError = new class('duplicate', '23505') extends AbstractException {};
        $connection->expects(self::once())->method('insert')->willThrowException(new UniqueConstraintViolationException($driverError, null));
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(RegistrationMode::Approval, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Approval));
    }

    #[Test]
    public function retriesCasWhenAnotherProcessChangesTheMode(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls('DIRECT', 'APPROVAL');
        $connection->expects(self::once())->method('executeStatement')->with(
            'UPDATE chanserv_registration_policy SET mode = ? WHERE id = 1 AND mode = ?',
            ['APPROVAL', 'DIRECT'],
        )->willReturn(0);

        self::assertSame(RegistrationMode::Approval, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Approval));
    }

    #[Test]
    public function returnsPreviousModeWhenCompareAndSwapSucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('DIRECT');
        $connection->expects(self::once())->method('executeStatement')->with(
            'UPDATE chanserv_registration_policy SET mode = ? WHERE id = 1 AND mode = ?',
            ['APPROVAL', 'DIRECT'],
        )->willReturn(1);

        self::assertSame(RegistrationMode::Direct, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Approval));
    }

    #[Test]
    public function boundedCasContentionFailsInsteadOfReportingUnappliedMode(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))->method('fetchOne')->willReturn('DIRECT');
        $connection->expects(self::exactly(3))->method('executeStatement')->willReturn(0);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Registration policy changed concurrently; retry the command.');

        new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Approval);
    }
}
