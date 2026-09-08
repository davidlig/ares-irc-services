<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelModeActions;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelModeActions::class)]
final class IrcChannelModeActionsTest extends TestCase
{
    #[Test]
    public function itMapsSemanticChangesWithoutLosingCaseOrParameterOrder(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::once())->method('setChannelModes')->with(
            '#Channel',
            '-kn+lR-L',
            ['secret', '50', '#redirect'],
        );

        new IrcChannelModeActions($network)->apply('#Channel', [
            new ModeChange(new ModeName('k'), ModeChangeAction::Remove, 'secret'),
            new ModeChange(new ModeName('n'), ModeChangeAction::Remove),
            new ModeChange(new ModeName('l'), ModeChangeAction::Add, '50'),
            new ModeChange(new ModeName('R'), ModeChangeAction::Add),
            new ModeChange(new ModeName('L'), ModeChangeAction::Remove, '#redirect'),
        ]);
    }

    #[Test]
    public function itDoesNotSendAnEmptyModeChange(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::never())->method('setChannelModes');

        new IrcChannelModeActions($network)->apply('#channel', []);
    }
}
