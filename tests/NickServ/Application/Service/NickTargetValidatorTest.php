<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(NickTargetValidator::class)]
final class NickTargetValidatorTest extends TestCase
{
    #[Test]
    public function validateWithRootNickReturnsIsRoot(): void
    {
        $exemption = $this->createStub(NickProtectionExemption::class);
        $exemption->method('isRootNickname')->willReturn(true);
        $validator = $this->createValidator(protectionExemption: $exemption);

        $result = $validator->validate('RootAdmin');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsRoot, $result->status);
        self::assertSame('RootAdmin', $result->nickname);
    }

    #[Test]
    public function validateWithServiceNickReturnsIsService(): void
    {
        $exemption = $this->createStub(NickProtectionExemption::class);
        $exemption->method('isServiceNickname')->willReturn(true);
        $validator = $this->createValidator(protectionExemption: $exemption);

        $result = $validator->validate('NickServ');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsService, $result->status);
    }

    #[Test]
    public function validateWithIrcopNickReturnsIsIrcop(): void
    {
        $nick = $this->createNickWithId('OperUser', 42);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $exemption = $this->createStub(NickProtectionExemption::class);
        $exemption->method('isIrcopNickId')->willReturn(true);

        $validator = $this->createValidator(
            nickRepository: $nickRepository,
            protectionExemption: $exemption,
        );

        $result = $validator->validate('OperUser');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsIrcop, $result->status);
    }

    #[Test]
    public function validateWithUnregisteredNickReturnsAllowed(): void
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $validator = $this->createValidator(nickRepository: $nickRepository);

        $result = $validator->validate('NewUser');

        self::assertTrue($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::Allowed, $result->status);
        self::assertNull($result->account);
    }

    #[Test]
    public function validateWithRegisteredNonIrcopNickReturnsAllowed(): void
    {
        $nick = $this->createNickWithId('RegularUser', 99);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createValidator(
            nickRepository: $nickRepository,
        );

        $result = $validator->validate('RegularUser');

        self::assertTrue($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::Allowed, $result->status);
        self::assertSame($nick, $result->account);
    }

    #[Test]
    public function validateIsCaseInsensitive(): void
    {
        $exemption = $this->createMock(NickProtectionExemption::class);
        $exemption->expects(self::once())->method('isRootNickname')->with('rootadmin')->willReturn(true);
        $validator = $this->createValidator(protectionExemption: $exemption);

        $result = $validator->validate('rootadmin');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsRoot, $result->status);
    }

    private function createValidator(
        ?NickProtectionExemption $protectionExemption = null,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
    ): NickTargetValidator {
        return new NickTargetValidator(
            $protectionExemption ?? $this->createStub(NickProtectionExemption::class),
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
        );
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
