<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\EmailVerificationSettingDoctrineRepository;
use App\NickServ\Domain\Entity\EmailVerificationSetting;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(EmailVerificationSettingDoctrineRepository::class)]
#[Group('integration')]
final class EmailVerificationSettingDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private EmailVerificationSettingDoctrineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EmailVerificationSettingDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function verificationIsRequiredWhenNoSettingHasBeenStored(): void
    {
        self::assertTrue($this->repository->isVerificationRequired());
    }

    #[Test]
    public function storesADisabledPolicy(): void
    {
        $this->repository->setVerificationRequired(false);
        $this->flushAndClear();

        self::assertFalse($this->repository->isVerificationRequired());
    }

    #[Test]
    public function storesAnExplicitlyEnabledPolicy(): void
    {
        $this->repository->setVerificationRequired(true);
        $this->flushAndClear();

        self::assertTrue($this->repository->isVerificationRequired());
    }

    #[Test]
    public function updatesTheExistingRowInsteadOfInsertingAnother(): void
    {
        $this->repository->setVerificationRequired(false);
        $this->flushAndClear();
        $this->repository->setVerificationRequired(true);
        $this->flushAndClear();

        self::assertTrue($this->repository->isVerificationRequired());
        self::assertCount(
            1,
            $this->entityManager->getRepository(EmailVerificationSetting::class)->findAll(),
        );
    }
}
