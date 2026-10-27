<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRegistrationPolicyRepository;
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
    public function readsOnlyItsServiceAndSettingFromTheCommonStore(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE IF NOT EXISTS service_settings (service_name VARCHAR(32) NOT NULL, setting_name VARCHAR(64) NOT NULL, setting_value TEXT NOT NULL, PRIMARY KEY (service_name, setting_name))');
        $db->insert('service_settings', ['service_name' => 'nickserv', 'setting_name' => 'registration_mode', 'setting_value' => 'EMAIL']);
        $db->insert('service_settings', ['service_name' => 'chanserv', 'setting_name' => 'registration_mode', 'setting_value' => 'APPROVAL']);
        $db->insert('service_settings', ['service_name' => 'nickserv', 'setting_name' => 'other_setting', 'setting_value' => 'untouched']);
        $repository = new DoctrineRegistrationPolicyRepository($db);

        self::assertSame(RegistrationMode::Email, $repository->current());
        self::assertSame(RegistrationMode::Email, $repository->change(RegistrationMode::Token));
        self::assertSame('APPROVAL', $db->fetchOne("SELECT setting_value FROM service_settings WHERE service_name = 'chanserv' AND setting_name = 'registration_mode'"));
        self::assertSame('untouched', $db->fetchOne("SELECT setting_value FROM service_settings WHERE service_name = 'nickserv' AND setting_name = 'other_setting'"));
    }

    #[Test]
    public function missingPolicyDefaultsToTokenAndSurvivesRepositoryRestart(): void
    {
        $repository = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Token, $repository->current());
        self::assertSame(RegistrationMode::Token, $repository->change(RegistrationMode::Token));
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM service_settings WHERE service_name = \'nickserv\''));
        self::assertSame(RegistrationMode::Token, $repository->change(RegistrationMode::Email));
        $this->entityManager->clear();
        $restarted = new DoctrineRegistrationPolicyRepository($this->entityManager->getConnection());
        self::assertSame(RegistrationMode::Email, $restarted->current());
        self::assertSame(RegistrationMode::Email, $restarted->change(RegistrationMode::Email));
        self::assertSame(RegistrationMode::Email, $restarted->change(RegistrationMode::Token));
        self::assertSame(RegistrationMode::Token, $repository->current());
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM service_settings WHERE service_name = \'nickserv\''));
    }

    #[Test]
    public function concurrentSettingCreationRetriesUsingLiveState(): void
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
        $connection->expects(self::once())->method('executeStatement')->with('UPDATE service_settings SET setting_value = ? WHERE service_name = ? AND setting_name = ? AND setting_value = ?', ['EMAIL', 'nickserv', 'registration_mode', 'TOKEN'])->willReturn(0);
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
