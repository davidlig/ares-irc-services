<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\Command\AccessCommand;
use App\ChanServ\Adapter\In\Irc\Command\AdminCommand;
use App\ChanServ\Adapter\In\Irc\Command\AkickCommand;
use App\ChanServ\Adapter\In\Irc\Command\DeadminCommand;
use App\ChanServ\Adapter\In\Irc\Command\DehalfopCommand;
use App\ChanServ\Adapter\In\Irc\Command\DelaccessCommand;
use App\ChanServ\Adapter\In\Irc\Command\DeopCommand;
use App\ChanServ\Adapter\In\Irc\Command\DevoiceCommand;
use App\ChanServ\Adapter\In\Irc\Command\DropCommand;
use App\ChanServ\Adapter\In\Irc\Command\ForbidCommand;
use App\ChanServ\Adapter\In\Irc\Command\HalfopCommand;
use App\ChanServ\Adapter\In\Irc\Command\HelpCommand;
use App\ChanServ\Adapter\In\Irc\Command\InfoCommand;
use App\ChanServ\Adapter\In\Irc\Command\InviteCommand;
use App\ChanServ\Adapter\In\Irc\Command\LevelsCommand;
use App\ChanServ\Adapter\In\Irc\Command\OpCommand;
use App\ChanServ\Adapter\In\Irc\Command\RegisterCommand;
use App\ChanServ\Adapter\In\Irc\Command\SetCommand;
use App\ChanServ\Adapter\In\Irc\Command\SuspendCommand;
use App\ChanServ\Adapter\In\Irc\Command\UnforbidCommand;
use App\ChanServ\Adapter\In\Irc\Command\UnsuspendCommand;
use App\ChanServ\Adapter\In\Irc\Command\VoiceCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(AccessCommand::class)]
#[CoversClass(AdminCommand::class)]
#[CoversClass(AkickCommand::class)]
#[CoversClass(DeadminCommand::class)]
#[CoversClass(DehalfopCommand::class)]
#[CoversClass(DelaccessCommand::class)]
#[CoversClass(DeopCommand::class)]
#[CoversClass(DevoiceCommand::class)]
#[CoversClass(DropCommand::class)]
#[CoversClass(ForbidCommand::class)]
#[CoversClass(HalfopCommand::class)]
#[CoversClass(HelpCommand::class)]
#[CoversClass(InfoCommand::class)]
#[CoversClass(InviteCommand::class)]
#[CoversClass(LevelsCommand::class)]
#[CoversClass(OpCommand::class)]
#[CoversClass(RegisterCommand::class)]
#[CoversClass(SetCommand::class)]
#[CoversClass(SuspendCommand::class)]
#[CoversClass(UnforbidCommand::class)]
#[CoversClass(UnsuspendCommand::class)]
#[CoversClass(VoiceCommand::class)]
final class UsesLevelFounderTest extends TestCase
{
    /**
     * @return array<string, array{class-string, bool}>
     */
    public static function commandDataProvider(): array
    {
        return [
            'SET uses level founder' => [SetCommand::class, true],
            'ACCESS uses level founder' => [AccessCommand::class, true],
            'AKICK uses level founder' => [AkickCommand::class, true],
            'OP uses level founder' => [OpCommand::class, true],
            'DEOP uses level founder' => [DeopCommand::class, true],
            'VOICE uses level founder' => [VoiceCommand::class, true],
            'DEVOICE uses level founder' => [DevoiceCommand::class, true],
            'HALFOP uses level founder' => [HalfopCommand::class, true],
            'DEHALFOP uses level founder' => [DehalfopCommand::class, true],
            'ADMIN uses level founder' => [AdminCommand::class, true],
            'DEADMIN uses level founder' => [DeadminCommand::class, true],
            'LEVELS uses level founder' => [LevelsCommand::class, true],
            'INVITE uses level founder' => [InviteCommand::class, true],
            'DELACCESS does not use level founder' => [DelaccessCommand::class, false],
            'HELP does not use level founder' => [HelpCommand::class, false],
            'INFO does not use level founder' => [InfoCommand::class, false],
            'REGISTER does not use level founder' => [RegisterCommand::class, false],
            'DROP does not use level founder' => [DropCommand::class, false],
            'SUSPEND does not use level founder' => [SuspendCommand::class, false],
            'UNSUSPEND does not use level founder' => [UnsuspendCommand::class, false],
            'FORBID does not use level founder' => [ForbidCommand::class, false],
            'UNFORBID does not use level founder' => [UnforbidCommand::class, false],
        ];
    }

    /**
     * @param class-string $handlerClass
     */
    #[Test]
    #[DataProvider('commandDataProvider')]
    public function usesLevelFounderReturnsExpectedValue(string $handlerClass, bool $expected): void
    {
        $method = new ReflectionMethod($handlerClass, 'usesLevelFounder');
        $instance = new ReflectionClass($handlerClass)
            ->newInstanceWithoutConstructor();
        self::assertSame($expected, $method->invoke($instance));
    }
}
