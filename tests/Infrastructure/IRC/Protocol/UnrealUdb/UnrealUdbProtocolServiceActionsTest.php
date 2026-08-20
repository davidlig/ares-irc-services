<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolServiceActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealUdbProtocolServiceActions::class)]
final class UnrealUdbProtocolServiceActionsTest extends TestCase
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
        $property->setValue($this->connectionHolder, $connection);
    }

    #[Test]
    public function setUserAccountSendsSvsloginAndSvs2modeForLogin(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->setUserAccount('001', '123', 'account');

        self::assertCount(2, $this->written);
        self::assertSame(':001 SVSLOGIN * 123 account', $this->written[0]);
        self::assertSame(':001 SVS2MODE 123 +r', $this->written[1]);
    }

    #[Test]
    public function setUserAccountSendsSvsloginAndSvs2modeForLogout(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->setUserAccount('001', '123', '0');

        self::assertCount(2, $this->written);
        self::assertSame(':001 SVSLOGIN * 123 0', $this->written[0]);
        self::assertSame(':001 SVS2MODE 123 -r', $this->written[1]);
    }

    #[Test]
    public function setUserModeSendsSvsmode(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setUserMode('001', '001ABCD', '+i');

        self::assertCount(1, $this->written);
        self::assertSame(':001 SVSMODE 001ABCD +i', $this->written[0]);
    }

    #[Test]
    public function forceNickSendsSvsnickWithTimestamp(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->forceNick('001', '001ABCD', 'NewNick');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 SVSNICK 001ABCD NewNick \d+$/', $this->written[0]);
    }

    #[Test]
    public function killUserSendsKillCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->killUser('001', '001ABCD', 'Killed for abuse');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KILL 001ABCD :Killed for abuse', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromServer(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+nt');

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +nt', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromService(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+o', ['001ABCD'], '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsModeFromServer(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeRemovesMode(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', false);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test -o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function inviteUserToChannelSendsInvite(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->inviteUserToChannel('001', '#test', '001ABCD');

        self::assertCount(1, $this->written);
        self::assertSame(':001 INVITE 001ABCD #test', $this->written[0]);
    }

    #[Test]
    public function joinChannelAsServiceSendsJoin(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'q');

        self::assertCount(2, $this->written);
        self::assertSame(':001CSRV JOIN #test', $this->written[0]);
        self::assertSame(':001CSRV MODE #test +q 001CSRV', $this->written[1]);
    }

    #[Test]
    public function joinChannelAsServiceSkipsPrefixWhenEmpty(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', '');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV JOIN #test', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicSetsTopic(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', 'New topic', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV TOPIC #test :New topic', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicClearsTopicWhenNull(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', null, '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV TOPIC #test', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelSendsKickCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'Kicked for abuse', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV KICK #test 001ABCD :Kicked for abuse', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelUsesServerSidWhenServiceUidEmpty(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'reason');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KICK #test 001ABCD :reason', $this->written[0]);
    }

    #[Test]
    public function methodsDoNothingWhenDisconnected(): void
    {
        $connectionHolder = new ActiveConnectionHolder();

        $actions = new UnrealUdbProtocolServiceActions($connectionHolder);

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
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->addGline('001', 'testuser', 'test.host', 3600, 'Test ban');
        $this->assertCount(1, $this->written);
        $this->assertSame('DB * INS K::G::testuser@test.host', $this->written[0]);
    }

    #[Test]
    public function addGlinePermanentBan(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->addGline('001', '*', '192.168.*', 0, 'Permanent ban');
        $this->assertCount(1, $this->written);
        $this->assertSame('DB * INS K::G::*@192.168.*', $this->written[0]);
    }

    #[Test]
    public function removeGlineSendsTklRemoveCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->removeGline('001', 'testuser', 'test.host');
        $this->assertCount(1, $this->written);
        $this->assertSame('DB * DEL K::G::testuser@test.host', $this->written[0]);
    }

    #[Test]
    public function removeGlineWithWildcards(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);
        $actions->removeGline('001', '*', '192.168.*');
        $this->assertCount(1, $this->written);
        $this->assertSame('DB * DEL K::G::*@192.168.*', $this->written[0]);
    }

    #[Test]
    public function introducePseudoClientSendsUidCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->introducePseudoClient('001', 'GlobalBot', 'global', 'services.red', '001Z00001', 'Global Message Bot');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 UID GlobalBot 1 \d+ global services\.red 001Z00001 0 \+BDIopqR services\.red \* \* \* :Global Message Bot$/', $this->written[0]);
    }

    #[Test]
    public function introducePseudoClientWithDifferentParams(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->introducePseudoClient('002', 'Announce', 'announce', 'irc.example.net', '002Z00005', 'Network Announcements');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 UID Announce 1 \d+ announce irc\.example\.net 002Z00005 0 \+BDIopqR irc\.example\.net \* \* \* :Network Announcements$/', $this->written[0]);
    }

    #[Test]
    public function quitPseudoClientSendsQuitCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->quitPseudoClient('001', '001Z00001', 'Global message completed');

        self::assertCount(1, $this->written);
        self::assertSame(':001Z00001 QUIT :Global message completed', $this->written[0]);
    }

    #[Test]
    public function partChannelAsServiceSendsPartCommand(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->partChannelAsService('001', '#test', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV PART #test', $this->written[0]);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenSettingVhost(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setUserVhost('001', '001ABCD', 'custom.vhost.net');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenSettingVhostWithSpaces(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setUserVhost('001', '001ABCD', 'custom vhost with spaces');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenClearingVhost(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->setUserVhost('001', '001ABCD', '');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function introduceServiceSendsFormattedServiceIntroduction(): void
    {
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder);

        $actions->introduceService('001', 'NickServ', 'NickServ', 'services.host', '001AAAAAA', 'Nickname Services', 'nickserv');

        self::assertCount(1, $this->written);
        self::assertStringContainsString(':001 UID NickServ 1', $this->written[0]);
        self::assertStringContainsString('001AAAAAA', $this->written[0]);
    }
}
