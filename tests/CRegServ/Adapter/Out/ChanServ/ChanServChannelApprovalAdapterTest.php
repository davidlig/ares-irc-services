<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\CRegServ\Adapter\Out\ChanServ\ChanServChannelApprovalAdapter;
use App\CRegServ\Application\Port\Out\ChannelApprovalOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * This adapter is the single point of contact between CReG and ChanServ: every mapping below
 * decides what APROBAR tells the oper, so none of them may drift.
 */
#[CoversClass(ChanServChannelApprovalAdapter::class)]
final class ChanServChannelApprovalAdapterTest extends TestCase
{
    private function adapterReturning(ChannelRegistrationOutcome $outcome): ChanServChannelApprovalAdapter
    {
        $registration = $this->createStub(ChannelRegistration::class);
        $registration->method('registerApproved')->willReturn($outcome);

        return new ChanServChannelApprovalAdapter($registration);
    }

    /**
     * @return array<string, array{ChannelRegistrationOutcome, ChannelApprovalOutcome}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'registered' => [ChannelRegistrationOutcome::Registered, ChannelApprovalOutcome::Approved],
            'a deletion still in flight' => [
                ChannelRegistrationOutcome::PendingDeletion,
                ChannelApprovalOutcome::AlreadyRegistered,
            ],
            'already there' => [
                ChannelRegistrationOutcome::AlreadyRegistered,
                ChannelApprovalOutcome::AlreadyRegistered,
            ],
            'ChanServ refused' => [ChannelRegistrationOutcome::Failed, ChannelApprovalOutcome::Failed],
        ];
    }

    #[Test]
    #[DataProvider('outcomeProvider')]
    public function mapsEveryChanServOutcomeOntoTheApprovalOutcome(
        ChannelRegistrationOutcome $given,
        ChannelApprovalOutcome $expected,
    ): void {
        $adapter = $this->adapterReturning($given);

        $outcome = $adapter->approve('#SitioChat', 'Canal de pruebas', 7, 'NetAdmin', new DateTimeImmutable());

        self::assertSame($expected, $outcome);
    }

    #[Test]
    public function handsTheWholeRequestOverToChanServUnchanged(): void
    {
        $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00');

        $registration = $this->createMock(ChannelRegistration::class);
        $registration->expects(self::once())
            ->method('registerApproved')
            ->with('#SitioChat', 'Canal de pruebas', 7, 'NetAdmin', $occurredAt)
            ->willReturn(ChannelRegistrationOutcome::Registered);

        $adapter = new ChanServChannelApprovalAdapter($registration);

        self::assertSame(
            ChannelApprovalOutcome::Approved,
            $adapter->approve('#SitioChat', 'Canal de pruebas', 7, 'NetAdmin', $occurredAt),
        );
    }
}
