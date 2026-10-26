<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\RegistrationMode;

use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\ChanServ\Application\Port\Out\RegistrationPolicyRepository;
use App\ChanServ\Application\UseCase\RegistrationMode\RegistrationModeHandler;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrationModeHandler::class)]
final class RegistrationModeHandlerTest extends TestCase
{
    #[Test]
    public function currentUsesPersistedMode(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::once())->method('current')->willReturn(RegistrationMode::Approval);
        $audit = $this->createStub(ChanAuditSink::class);

        self::assertSame(RegistrationMode::Approval, new RegistrationModeHandler($repository, $audit)->current());
    }

    #[Test]
    public function modeChangesAreAuditedToConfiguredChannelSink(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::once())->method('change')->with(RegistrationMode::Approval)->willReturn(RegistrationMode::Direct);
        $audit = $this->createMock(ChanAuditSink::class);
        $audit->expects(self::once())->method('log')->with(
            operator: 'OperNick',
            command: 'REGMODE',
            target: 'registration-mode',
            targetHost: null,
            targetIp: null,
            reason: 'APPROVAL',
            extra: ['previous' => 'DIRECT', 'mode' => 'APPROVAL'],
        );

        self::assertSame(RegistrationMode::Direct, new RegistrationModeHandler($repository, $audit)->change(RegistrationMode::Approval, 'OperNick'));
    }

    #[Test]
    public function unchangedModeDoesNotCreateAnAuditEntry(): void
    {
        $repository = $this->createMock(RegistrationPolicyRepository::class);
        $repository->expects(self::once())->method('change')->with(RegistrationMode::Direct)->willReturn(RegistrationMode::Direct);
        $audit = $this->createMock(ChanAuditSink::class);
        $audit->expects(self::never())->method('log');

        self::assertSame(RegistrationMode::Direct, new RegistrationModeHandler($repository, $audit)->change(RegistrationMode::Direct, 'OperNick'));
    }
}
