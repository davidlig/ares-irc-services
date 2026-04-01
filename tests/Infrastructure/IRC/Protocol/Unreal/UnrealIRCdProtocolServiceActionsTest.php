<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdProtocolServiceActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealIRCdProtocolServiceActions::class)]
final class UnrealIRCdProtocolServiceActionsTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private array $written = [];

    protected function setUp(): void
    {
        $this->written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);

        $this->connectionHolder = new ActiveConnectionHolder();

        $reflection = new ReflectionClass($this->connectionHolder);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $property->setValue($this->connectionHolder, $connection);
    }

    #[Test]
    public function setUserAccountSetsRegisteredMode(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserAccount('001', '001ABCD', 'TestAccount');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SVS2MODE 001ABCD +r', $this->written[0]);
    }

    #[Test]
    public function setUserAccountClearsAccountWhenZero(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserAccount('001', '001ABCD', '0');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SVS2MODE 001ABCD -r', $this->written[0]);
    }

    #[Test]
    public function setUserModeSendsSvsmode(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserMode('001', '001ABCD', '+i');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SVSMODE 001ABCD +i', $this->written[0]);
    }

    #[Test]
    public function forceNickSendsSvsnickWithTimestamp(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->forceNick('001', '001ABCD', 'NewNick');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 SVSNICK 001ABCD NewNick \d+$/', $this->written[0]);
    }

    #[Test]
    public function killUserSendsKillCommand(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->killUser('001', '001ABCD', 'Killed for abuse');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KILL 001ABCD :Killed for abuse', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromServer(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+nt');

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +nt', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromService(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+o', ['001ABCD'], '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsModeFromServer(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeRemovesMode(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', false);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test -o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function inviteUserToChannelSendsInvite(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->inviteUserToChannel('001', '#test', '001ABCD');

        self::assertCount(1, $this->written);
        self::assertSame(':001 INVITE 001ABCD #test', $this->written[0]);
    }

    #[Test]
    public function joinChannelAsServiceSendsJoin(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'q');

        self::assertCount(2, $this->written);
        self::assertSame(':001CSRV JOIN #test', $this->written[0]);
        self::assertSame(':001CSRV MODE #test +q 001CSRV', $this->written[1]);
    }

    #[Test]
    public function joinChannelAsServiceSkipsPrefixWhenEmpty(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', '');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV JOIN #test', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicSetsTopic(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', 'New topic', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV TOPIC #test :New topic', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicClearsTopicWhenNull(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', null, '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV TOPIC #test', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelSendsKickCommand(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'Kicked for abuse', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV KICK #test 001ABCD :Kicked for abuse', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelUsesServerSidWhenServiceUidEmpty(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'reason');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KICK #test 001ABCD :reason', $this->written[0]);
    }

    #[Test]
    public function methodsDoNothingWhenDisconnected(): void
    {
        $connectionHolder = new ActiveConnectionHolder();

        $actions = new UnrealIRCdProtocolServiceActions($connectionHolder);

        $actions->setUserAccount('001', '001ABCD', 'TestAccount');
        $actions->setUserMode('001', '001ABCD', '+i');
        $actions->forceNick('001', '001ABCD', 'NewNick');
        $actions->killUser('001', '001ABCD', 'reason');
        $actions->setChannelModes('001', '#test', '+nt');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function addGlineSendsTklGlineCommand(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->addGline('001', 'testuser', 'test.host', 3600, 'Test ban');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^TKL \+ G testuser test\.host 001 \d+ \d+ :Test ban$/', $this->written[0]);
    }

    #[Test]
    public function addGlinePermanentBan(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->addGline('001', '*', '192.168.*', 0, 'Permanent ban');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^TKL \+ G \* 192\.168\.\* 001 0 \d+ :Permanent ban$/', $this->written[0]);
    }

    #[Test]
    public function removeGlineSendsTklRemoveCommand(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->removeGline('001', 'testuser', 'test.host');

        self::assertCount(1, $this->written);
        self::assertSame('TKL - G testuser test.host 001', $this->written[0]);
    }

    #[Test]
    public function removeGlineWithWildcards(): void
    {
        $actions = new UnrealIRCdProtocolServiceActions($this->connectionHolder);

        $actions->removeGline('001', '*', '192.168.*');

        self::assertCount(1, $this->written);
        self::assertSame('TKL - G * 192.168.* 001', $this->written[0]);
    }
}
