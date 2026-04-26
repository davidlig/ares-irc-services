<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(InspIRCdProtocolServiceActions::class)]
final class InspIRCdProtocolServiceActionsTest extends TestCase
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
    public function setUserAccountSendsAccountMetadataAndSetsMode(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserAccount('001', '001ABCD', 'TestAccount');

        self::assertCount(4, $this->written);
        self::assertSame(':001 METADATA 001ABCD accountid :TestAccount', $this->written[0]);
        self::assertSame(':001 METADATA 001ABCD accountname :TestAccount', $this->written[1]);
        self::assertSame(':001 METADATA 001ABCD accountnicks :TestAccount', $this->written[2]);
        self::assertSame(':001 MODE 001ABCD +r', $this->written[3]);
    }

    #[Test]
    public function setUserAccountClearsAccountWhenZero(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserAccount('001', '001ABCD', '0');

        self::assertCount(4, $this->written);
        self::assertSame(':001 METADATA 001ABCD accountid :', $this->written[0]);
        self::assertSame(':001 METADATA 001ABCD accountname :', $this->written[1]);
        self::assertSame(':001 METADATA 001ABCD accountnicks :', $this->written[2]);
        self::assertSame(':001 MODE 001ABCD -r', $this->written[3]);
    }

    #[Test]
    public function setUserModeSendsMode(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setUserMode('001', '001ABCD', '+i');

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE 001ABCD +i', $this->written[0]);
    }

    #[Test]
    public function forceNickSendsSvsnickWithTimestamp(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->forceNick('001', '001ABCD', 'NewNick');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 SVSNICK 001ABCD NewNick \d+$/', $this->written[0]);
    }

    #[Test]
    public function killUserSendsKillCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->killUser('001', '001ABCD', 'Killed for abuse');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KILL 001ABCD :Killed for abuse', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsFmodeFromServerWhenTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+nt', [], '', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001 FMODE #test 12345 +nt', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsFmodeWithParamsWhenTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+k', ['secretkey'], '', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001 FMODE #test 12345 +k secretkey', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsFmodeFromServiceWhenTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+k', ['secretkey'], '001CSRV', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV FMODE #test 12345 +k secretkey', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsFmodeFromServerWhenTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true, '', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001 FMODE #test 12345 +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsFmodeWhenRemovingAndTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', false, '', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001 FMODE #test 12345 -o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsFmodeFromServiceWhenTimestampProvided(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true, '001CSRV', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV FMODE #test 12345 +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromServer(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+nt');

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +nt', $this->written[0]);
    }

    #[Test]
    public function setChannelModesSendsModeFromService(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelModes('001', '#test', '+o', ['001ABCD'], '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeSendsModeFromServer(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test +o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function setChannelMemberModeRemovesMode(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', false);

        self::assertCount(1, $this->written);
        self::assertSame(':001 MODE #test -o 001ABCD', $this->written[0]);
    }

    #[Test]
    public function inviteUserToChannelSendsInviteWithoutTimestampWhenNull(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->inviteUserToChannel('001', '#test', '001ABCD');

        self::assertCount(1, $this->written);
        self::assertSame(':001 INVITE 001ABCD #test', $this->written[0]);
    }

    #[Test]
    public function inviteUserToChannelSendsInviteWithChannelTimestamp(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->inviteUserToChannel('001', '#test', '001ABCD', '', 1777168016);

        self::assertCount(1, $this->written);
        self::assertSame(':001 INVITE 001ABCD #test 1777168016', $this->written[0]);
    }

    #[Test]
    public function inviteUserToChannelSendsInviteWithServiceUidAndTimestamp(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->inviteUserToChannel('001', '#test', '001ABCD', '001CSRV', 1777168016);

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV INVITE 001ABCD #test 1777168016', $this->written[0]);
    }

    #[Test]
    public function joinChannelAsServiceSendsFjoinWithOwnerPrefix(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'q');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 FJOIN #test \d+ 0 :q,001CSRV:$/', $this->written[0]);
    }

    #[Test]
    public function joinChannelAsServiceUsesOperatorWhenInvalidPrefix(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', '');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 FJOIN #test \d+ 0 :o,001CSRV:$/', $this->written[0]);
    }

    #[Test]
    public function joinChannelAsServiceUsesChannelTimestamp(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'o', 12345);

        self::assertCount(1, $this->written);
        self::assertSame(':001 FJOIN #test 12345 0 :o,001CSRV:', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicSetsFtopicWithTimestampsFromService(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', 'New topic', '001CSRV', 12345);

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001CSRV FTOPIC #test 12345 \d+ :New topic$/', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicSetsFtopicFromServerWhenNoServiceUid(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', 'New topic', '', 12345);

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 FTOPIC #test 12345 \d+ :New topic$/', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicUsesCurrentTimeWhenNoCreationTs(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', 'New topic');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 FTOPIC #test \d+ \d+ :New topic$/', $this->written[0]);
    }

    #[Test]
    public function setChannelTopicClearsTopicWhenNull(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->setChannelTopic('001', '#test', null, '001CSRV', 12345);

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001CSRV FTOPIC #test 12345 \d+ :$/', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelSendsKickWithoutMembershipId(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'Kicked for abuse', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV KICK #test 001ABCD :Kicked for abuse', $this->written[0]);
    }

    #[Test]
    public function kickFromChannelUsesServerSidWhenServiceUidEmpty(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->kickFromChannel('001', '#test', '001ABCD', 'reason');

        self::assertCount(1, $this->written);
        self::assertSame(':001 KICK #test 001ABCD :reason', $this->written[0]);
    }

    #[Test]
    public function methodsDoNothingWhenDisconnected(): void
    {
        $connectionHolder = new ActiveConnectionHolder();

        $actions = new InspIRCdProtocolServiceActions($connectionHolder);

        $actions->setUserAccount('001', '001ABCD', 'TestAccount');
        $actions->setUserMode('001', '001ABCD', '+i');
        $actions->forceNick('001', '001ABCD', 'NewNick');
        $actions->killUser('001', '001ABCD', 'reason');
        $actions->setChannelModes('001', '#test', '+nt');

        self::assertEmpty($this->written);
    }

    #[Test]
    public function addGlineSendsAddlineGCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->addGline('001', 'testuser', 'test.host', 3600, 'Test ban');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE G testuser@test.host 001 \d+ 3600 :Test ban$/', $this->written[0]);
    }

    #[Test]
    public function addGlinePermanentBan(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->addGline('001', '*', '192.168.*', 0, 'Permanent ban');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 ADDLINE G \*@192\.168\.\* 001 \d+ 0 :Permanent ban$/', $this->written[0]);
    }

    #[Test]
    public function removeGlineSendsDellineGCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->removeGline('001', 'testuser', 'test.host');

        self::assertCount(1, $this->written);
        self::assertSame(':001 DELLINE G testuser@test.host', $this->written[0]);
    }

    #[Test]
    public function removeGlineWithWildcards(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->removeGline('001', '*', '192.168.*');

        self::assertCount(1, $this->written);
        self::assertSame(':001 DELLINE G *@192.168.*', $this->written[0]);
    }

    #[Test]
    public function introducePseudoClientSendsUidCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->introducePseudoClient('001', 'GlobalBot', 'global', 'services.red', '001Z00001', 'Global Message Bot');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 UID 001Z00001 \d+ GlobalBot services\.red services\.red global global 0\.0\.0\.0 \d+ \+B :Global Message Bot$/', $this->written[0]);
    }

    #[Test]
    public function introducePseudoClientWithDifferentParams(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->introducePseudoClient('002', 'Announce', 'announce', 'irc.example.net', '002Z00005', 'Network Announcements');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 UID 002Z00005 \d+ Announce irc\.example\.net irc\.example\.net announce announce 0\.0\.0\.0 \d+ \+B :Network Announcements$/', $this->written[0]);
    }

    #[Test]
    public function quitPseudoClientSendsQuitCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->quitPseudoClient('001', '001Z00001', 'Global message completed');

        self::assertCount(1, $this->written);
        self::assertSame(':001Z00001 QUIT :Global message completed', $this->written[0]);
    }

    #[Test]
    public function partChannelAsServiceSendsPartCommand(): void
    {
        $actions = new InspIRCdProtocolServiceActions($this->connectionHolder);

        $actions->partChannelAsService('001', '#test', '001CSRV');

        self::assertCount(1, $this->written);
        self::assertSame(':001CSRV PART #test', $this->written[0]);
    }
}
