<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
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
        $rootRegistry = new RootUserRegistry('RootAdmin');
        $validator = $this->createValidator(rootRegistry: $rootRegistry);

        $result = $validator->validate('RootAdmin');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsRoot, $result->status);
        self::assertSame('RootAdmin', $result->nickname);
    }

    #[Test]
    public function validateWithServiceNickReturnsIsService(): void
    {
        $provider = new class implements ServiceUidProviderInterface, ServiceNicknameProviderInterface {
            public function getUid(): string
            {
                return '001AAAAAA';
            }

            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };

        $serviceUidRegistry = ServiceUidRegistry::fromIterable([$provider]);

        $validator = $this->createValidator(serviceUidRegistry: $serviceUidRegistry);

        $result = $validator->validate('NickServ');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsService, $result->status);
    }

    #[Test]
    public function validateWithIrcopNickReturnsIsIrcop(): void
    {
        $nick = $this->createNickWithId('OperUser', 42);

        $role = new OperRole('Admin', 'desc');
        $ircop = OperIrcop::create(42, $role);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $validator = $this->createValidator(
            nickRepository: $nickRepository,
            ircopRepository: $ircopRepository,
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

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $validator = $this->createValidator(
            nickRepository: $nickRepository,
            ircopRepository: $ircopRepository,
        );

        $result = $validator->validate('RegularUser');

        self::assertTrue($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::Allowed, $result->status);
        self::assertSame($nick, $result->account);
    }

    #[Test]
    public function validateIsCaseInsensitive(): void
    {
        $rootRegistry = new RootUserRegistry('RootAdmin');
        $validator = $this->createValidator(rootRegistry: $rootRegistry);

        $result = $validator->validate('rootadmin');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsRoot, $result->status);
    }

    private function createValidator(
        ?RootUserRegistry $rootRegistry = null,
        ?OperIrcopRepositoryInterface $ircopRepository = null,
        ?ServiceUidRegistry $serviceUidRegistry = null,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
    ): NickTargetValidator {
        return new NickTargetValidator(
            $rootRegistry ?? new RootUserRegistry(''),
            $ircopRepository ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $serviceUidRegistry ?? new ServiceUidRegistry([]),
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
        );
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
