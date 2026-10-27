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
    public function commonStoreUpdatesAreScopedToItsServiceAndSetting(): void
    {
        $db = $this->entityManager->getConnection();
        $db->insert('service_settings', ['service_name' => 'chanserv', 'setting_name' => 'registration_mode', 'setting_value' => 'DIRECT']);
        $db->insert('service_settings', ['service_name' => 'nickserv', 'setting_name' => 'registration_mode', 'setting_value' => 'EMAIL']);
        $db->insert('service_settings', ['service_name' => 'chanserv', 'setting_name' => 'other_setting', 'setting_value' => 'untouched']);
        $repository = new DoctrineRegistrationPolicyRepository($db);

        self::assertSame(RegistrationMode::Direct, $repository->change(RegistrationMode::Approval));
        self::assertSame(RegistrationMode::Approval, $repository->current());
        self::assertSame('EMAIL', $db->fetchOne("SELECT setting_value FROM service_settings WHERE service_name = 'nickserv' AND setting_name = 'registration_mode'"));
        self::assertSame('untouched', $db->fetchOne("SELECT setting_value FROM service_settings WHERE service_name = 'chanserv' AND setting_name = 'other_setting'"));
    }

    #[Test]
    public function readsLiveSettingAndPersistsModeAcrossRepositoryInstances(): void
    {
        $repository = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Direct, $repository->current());
        self::assertSame(RegistrationMode::Direct, $repository->change(RegistrationMode::Direct));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM service_settings WHERE service_name = \'chanserv\''));
        self::assertSame(RegistrationMode::Direct, $repository->change(RegistrationMode::Approval));
        self::assertSame(RegistrationMode::Approval, new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection())->current());
        self::assertSame(RegistrationMode::Approval, $repository->change(RegistrationMode::Approval));
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM service_settings WHERE service_name = \'chanserv\''));
    }

    #[Test]
    public function retriesConcurrentSettingCreationUsingTheWinningLiveMode(): void
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
            'UPDATE service_settings SET setting_value = ? WHERE service_name = ? AND setting_name = ? AND setting_value = ?',
            ['APPROVAL', 'chanserv', 'registration_mode', 'DIRECT'],
        )->willReturn(0);

        self::assertSame(RegistrationMode::Approval, new DoctrineRegistrationPolicyRepository($connection)->change(RegistrationMode::Approval));
    }

    #[Test]
    public function returnsPreviousModeWhenCompareAndSwapSucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('DIRECT');
        $connection->expects(self::once())->method('executeStatement')->with(
            'UPDATE service_settings SET setting_value = ? WHERE service_name = ? AND setting_name = ? AND setting_value = ?',
            ['APPROVAL', 'chanserv', 'registration_mode', 'DIRECT'],
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
