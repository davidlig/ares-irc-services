<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageLevels;

use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevels;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsAction;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsHandler;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsOutcome;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageChannelLevelsHandler::class)]
final class ManageChannelLevelsHandlerTest extends TestCase
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
        $handler = new ManageChannelLevelsHandler($channels, $levels);

        foreach ([[null, 10], ['', 10], ['ACCESSLIST', null]] as [$key, $value]) {
            $result = $handler->handle(new ManageChannelLevels(
                '#test',
                1,
                true,
                ManageChannelLevelsAction::Set,
                ['ACCESSLIST'],
                $key,
                $value,
            ));
            self::assertSame(ManageChannelLevelsOutcome::InvalidRequest, $result->outcome);
        }
    }
}
