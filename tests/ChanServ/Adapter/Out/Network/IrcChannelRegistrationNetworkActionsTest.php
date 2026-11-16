<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelRegistrationNetworkActions;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Shared\Domain\IrcIdentityFold;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelRegistrationNetworkActions::class)]
final class IrcChannelRegistrationNetworkActionsTest extends TestCase
{
    #[Test]
    public function reconciliationOnlyKeepsRegisteredModeOnChannelsEquivalentUnderTheNegotiatedMapping(): void
    {
        foreach ([[CaseMapping::Ascii, [['#foo{', '-r', []]]], [CaseMapping::Rfc1459, []]] as [$mapping, $expected]) {
            $support = $this->createStub(ChannelModeSupportInterface::class);
            $support->method('getChannelRegisteredModeLetter')->willReturn('r');
            $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
            $provider->method('getSupport')->willReturn($support);
            $lookup = $this->createStub(ChannelLookupPort::class);
            $lookup->method('findByChannelName')->willReturn(new ChannelView('#foo[', '+nr', null, 1));
            $lookup->method('iterateModeSnapshots')->willReturn([new ChannelView('#foo[', '+nr', null, 1), new ChannelView('#foo{', '+nr', null, 1)]);
            $calls = [];
            $actions = $this->createStub(ChannelServiceActionsPort::class);
            $actions->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$calls): void {
                $calls[] = [$channel, $modes, $params];
            });

            new IrcChannelRegistrationNetworkActions($provider, $lookup, $actions, NegotiatedCaseMapping::network($mapping))
                ->reconcileRegisteredMode([IrcIdentityFold::fold('#foo[') => '#foo['], ['#foo[']);

            self::assertSame($expected, $calls, $mapping->value);
        }
    }
}
