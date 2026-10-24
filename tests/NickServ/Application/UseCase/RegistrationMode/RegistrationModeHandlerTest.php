<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\RegistrationMode;

use App\NickServ\Application\Port\Out\RegistrationPolicyRepository;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeHandler;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeInput;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeResult;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrationModeHandler::class)]
#[CoversClass(RegistrationModeInput::class)]
#[CoversClass(RegistrationModeResult::class)]
final class RegistrationModeHandlerTest extends TestCase
{
    #[Test]
    public function denialDoesNotReadOrWritePolicy(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::never())->method('current');
        $repository->expects(self::never())->method('change');
        $result = new RegistrationModeHandler($repository)->handle(new RegistrationModeInput(false, RegistrationMode::Email));
        self::assertFalse($result->authorized);
        self::assertNull($result->mode);
        self::assertFalse($result->changed);
    }

    #[Test]
    public function statusReadsWithoutMutation(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::once())->method('current')->willReturn(RegistrationMode::Email);
        $repository->expects(self::never())->method('change');
        $result = new RegistrationModeHandler($repository)->handle(new RegistrationModeInput(true));
        self::assertTrue($result->authorized);
        self::assertSame(RegistrationMode::Email, $result->mode);
        self::assertFalse($result->changed);
    }

    #[Test]
    public function updatesModeAndDetectsNoop(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::exactly(2))->method('change')->with(RegistrationMode::Email)->willReturnOnConsecutiveCalls(RegistrationMode::Token, RegistrationMode::Email);
        $handler = new RegistrationModeHandler($repository);
        $result = $handler->handle(new RegistrationModeInput(true, RegistrationMode::Email));
        self::assertTrue($result->changed);
        self::assertSame(RegistrationMode::Email, $result->mode);
        self::assertFalse($handler->handle(new RegistrationModeInput(true, RegistrationMode::Email))->changed);
    }
}
