<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelDeregistrationOutcome;
use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\CRegServ\Adapter\Out\ChanServ\ChanServChannelDropAdapter;
use App\CRegServ\Application\Port\Out\ChannelDropOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * This adapter is the single point of contact between CReG and ChanServ: every mapping below
 * decides what DROP tells the oper, so none of them may drift.
 */
#[CoversClass(ChanServChannelDropAdapter::class)]
final class ChanServChannelDropAdapterTest extends TestCase
{
    private function adapterReturning(ChannelDeregistrationOutcome $outcome): ChanServChannelDropAdapter
    {
        $registration = $this->createStub(ChannelRegistration::class);
        $registration->method('dropRegistered')->willReturn($outcome);

        return new ChanServChannelDropAdapter($registration);
    }

    /**
     * @return array<string, array{ChannelDeregistrationOutcome, ChannelDropOutcome}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'dropped' => [ChannelDeregistrationOutcome::Dropped, ChannelDropOutcome::Dropped],
            'not registered' => [ChannelDeregistrationOutcome::NotRegistered, ChannelDropOutcome::NotRegistered],
            'ChanServ refused' => [ChannelDeregistrationOutcome::Failed, ChannelDropOutcome::Failed],
        ];
    }

    #[Test]
    #[DataProvider('outcomeProvider')]
    public function mapsEveryChanServOutcomeOntoTheDropOutcome(
        ChannelDeregistrationOutcome $given,
        ChannelDropOutcome $expected,
    ): void {
        $adapter = $this->adapterReturning($given);

        $outcome = $adapter->drop('#SitioChat', 'NetAdmin', new DateTimeImmutable());

        self::assertSame($expected, $outcome);
    }

    #[Test]
    public function handsTheDropOverToChanServUnchanged(): void
    {
        $occurredAt = new DateTimeImmutable('2026-10-15 12:00:00');

        $registration = $this->createMock(ChannelRegistration::class);
        $registration->expects(self::once())
            ->method('dropRegistered')
            ->with('#SitioChat', 'NetAdmin', $occurredAt)
            ->willReturn(ChannelDeregistrationOutcome::Dropped);

        $adapter = new ChanServChannelDropAdapter($registration);

        self::assertSame(
            ChannelDropOutcome::Dropped,
            $adapter->drop('#SitioChat', 'NetAdmin', $occurredAt),
        );
    }
}
