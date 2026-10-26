<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageAkick;

use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkick;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickAction;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickHandler;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageChannelAkickHandler::class)]
final class ManageChannelAkickHandlerTest extends TestCase
{
    public function testIncompleteActionInputHasNoSideEffects(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('save');
        $levels->expects(self::never())->method('removeAllForChannel');
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('save');
        $access->expects(self::never())->method('remove');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $policy = new ChanServAccessHelper($access, $levels);
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('save');
        $akicks->expects(self::never())->method('remove');
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::never())->method('synchronizationComplete');
        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::never())->method('banAndKick');
        $handler = new ManageChannelAkickHandler(
            $channels,
            $akicks,
            $accounts,
            $access,
            $policy,
            new AkickAdditionPolicy(),
            new AkickMatchPolicy(),
            new AkickProtectionPolicy(),
            $network,
            $actions,
            $events,
        );

        foreach ([[null, true], ['', true], ['*!*@example.test', false]] as [$item, $valid]) {
            $result = $handler->handle(new ManageChannelAkick(
                '#test',
                ManageChannelAkickAction::Delete,
                1,
                true,
                'Founder',
                'ip',
                'host',
                new DateTimeImmutable('2026-01-01'),
                item: $item,
                validRequest: $valid,
            ));
            self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);
        }
    }
}
