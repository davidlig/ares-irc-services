<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\Out\Irc\ConfiguredChanServMemoSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfiguredChanServMemoSender::class)]
final class ConfiguredChanServMemoSenderTest extends TestCase
{
    #[Test]
    public function returnsConfiguredChanServNickname(): void
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getNickname')->willReturn('ChannelBot');
        $registry = new ServiceNicknameRegistry([$provider]);

        self::assertSame('ChannelBot', new ConfiguredChanServMemoSender($registry)->getSenderName());
    }

    #[Test]
    public function refusesToFabricateNameWhenChanServIsNotRegistered(): void
    {
        $sender = new ConfiguredChanServMemoSender(new ServiceNicknameRegistry([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured ChanServ nickname is unavailable.');
        $sender->getSenderName();
    }
}
