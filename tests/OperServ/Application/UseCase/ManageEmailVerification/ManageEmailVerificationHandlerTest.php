<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageEmailVerification;

use App\OperServ\Application\Port\Out\EmailVerificationPolicyPortInterface;
use App\OperServ\Application\UseCase\ManageEmailVerification\EmailVerificationAction;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerification;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationHandler;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationOutcome;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationResult;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageEmailVerificationHandler::class)]
#[CoversClass(ManageEmailVerification::class)]
#[CoversClass(ManageEmailVerificationResult::class)]
#[CoversClass(EmailVerificationAction::class)]
#[CoversClass(ManageEmailVerificationOutcome::class)]
final class ManageEmailVerificationHandlerTest extends TestCase
{
    #[Test]
    public function reportsTheCurrentPolicyWithoutChangingIt(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(true);
        $policy->expects(self::never())->method('setVerificationRequired');

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Status));

        self::assertSame(ManageEmailVerificationOutcome::StatusReported, $result->outcome);
        self::assertTrue($result->required);
    }

    #[Test]
    public function statusReportsADisabledPolicy(): void
    {
        $policy = $this->createStub(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(false);

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Status));

        self::assertSame(ManageEmailVerificationOutcome::StatusReported, $result->outcome);
        self::assertFalse($result->required);
    }

    #[Test]
    public function enablesVerificationWhenItWasDisabled(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(false);
        $policy->expects(self::once())->method('setVerificationRequired')->with(true);

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Enable));

        self::assertSame(ManageEmailVerificationOutcome::Enabled, $result->outcome);
        self::assertTrue($result->required);
    }

    #[Test]
    public function enablingAnAlreadyEnabledPolicyIsIdempotent(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(true);
        $policy->expects(self::never())->method('setVerificationRequired');

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Enable));

        self::assertSame(ManageEmailVerificationOutcome::AlreadyEnabled, $result->outcome);
        self::assertTrue($result->required);
    }

    #[Test]
    public function disablesVerificationWhenItWasRequired(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(true);
        $policy->expects(self::once())->method('setVerificationRequired')->with(false);

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Disable));

        self::assertSame(ManageEmailVerificationOutcome::Disabled, $result->outcome);
        self::assertFalse($result->required);
    }

    #[Test]
    public function disablingAnAlreadyDisabledPolicyIsIdempotent(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(false);
        $policy->expects(self::never())->method('setVerificationRequired');

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Disable));

        self::assertSame(ManageEmailVerificationOutcome::AlreadyDisabled, $result->outcome);
        self::assertFalse($result->required);
    }

    #[Test]
    public function rejectsAnUnknownActionWithoutTouchingThePolicy(): void
    {
        $policy = $this->createMock(EmailVerificationPolicyPortInterface::class);
        $policy->method('isVerificationRequired')->willReturn(true);
        $policy->expects(self::never())->method('setVerificationRequired');

        $result = new ManageEmailVerificationHandler($policy)
            ->handle($this->command(EmailVerificationAction::Unknown));

        self::assertSame(ManageEmailVerificationOutcome::UnknownAction, $result->outcome);
    }

    private function command(EmailVerificationAction $action): ManageEmailVerification
    {
        return new ManageEmailVerification(
            action: $action,
            actor: 'Oper',
            actorAccountId: 7,
            occurredAt: new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
        );
    }
}
