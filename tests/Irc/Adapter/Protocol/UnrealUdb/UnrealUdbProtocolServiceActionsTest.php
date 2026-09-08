<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolServiceActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealUdbProtocolServiceActions::class)]
final class UnrealUdbProtocolServiceActionsTest extends TestCase
{
    use CreatesUdbRecordWriter;

    private ActiveConnectionHolder $connectionHolder;

    /** @var list<string> */
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
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($this->connectionHolder, '001');
    }

    private function createActions(): UnrealUdbProtocolServiceActions
    {
        return new UnrealUdbProtocolServiceActions(
            $this->connectionHolder,
            $this->createUdbRecordWriter($this->connectionHolder),
        );
    }

    #[Test]
    public function setUserAccountIsNoOp(): void
    {
        $actions = $this->createActions();
        $actions->setUserAccount('001', '123', 'account');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setUserModeIsNoOp(): void
    {
        $actions = $this->createActions();
        $actions->setUserMode('001', '001ABCD', '+i');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setUserOperclassRequiresReadyOclgAndDeletesWhenCleared(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $state = $this->createStub(UdbSessionStateInterface::class);
        $state->method('isOperclassGloballyAvailable')->willReturn(false);
        $writer->expects(self::once())->method('delete')->with('N', 'TestNick::oper');
        $writer->expects(self::never())->method('insert');
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder, $writer, $state);

        $actions->setUserOperclass('001', '001ABCD', 'TestNick', 'services:netadmin');
        $actions->setUserOperclass('001', '001ABCD', 'TestNick', null);
    }

    #[Test]
    public function setUserOperclassInsertsWhenTheOclgViewIsReady(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $state = $this->createStub(UdbSessionStateInterface::class);
        $state->method('isOperclassGloballyAvailable')->willReturn(true);
        $writer->expects(self::once())->method('insert')->with('N', 'TestNick::oper', 'services:netadmin');
        $writer->expects(self::never())->method('delete');
        $actions = new UnrealUdbProtocolServiceActions($this->connectionHolder, $writer, $state);

        $actions->setUserOperclass('001', '001ABCD', 'TestNick', 'services:netadmin');
    }

    #[Test]
    public function getAvailableOperclassesDelegatesToSessionStateOrReturnsEmptyWhenNull(): void
    {
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $actionsWithoutState = new UnrealUdbProtocolServiceActions($this->connectionHolder, $writer, null);
        self::assertSame([], $actionsWithoutState->getAvailableOperclasses());

        $state = $this->createStub(UdbSessionStateInterface::class);
        $state->method('getAvailableOperclasses')->willReturn(['locop', 'netadmin']);
        $actionsWithState = new UnrealUdbProtocolServiceActions($this->connectionHolder, $writer, $state);
        self::assertSame(['locop', 'netadmin'], $actionsWithState->getAvailableOperclasses());
    }

    #[Test]
    public function forceNickIsNoOp(): void
    {
        $actions = $this->createActions();
        $actions->forceNick('001', '001ABCD', 'NewNick');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function killUserSendsKillCommand(): void
    {
        $actions = $this->createActions();

        $actions->killUser('001', '001ABCD', 'Killed for abuse');

        self::assertSame([':001 KILL 001ABCD :Killed for abuse'], $this->written);
    }

    #[Test]
    public function setChannelModesSendsNativeMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+nt');

        self::assertSame([':001 MODE #test +nt'], $this->written);
    }

    #[Test]
    public function setChannelModesWithParamSendsNativeModeWithParam(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+k', ['secret']);

        self::assertSame([':001 MODE #test +k secret'], $this->written);
    }

    #[Test]
    public function setChannelModesNegativeModeSendsNativeMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '-nt');

        self::assertSame([':001 MODE #test -nt'], $this->written);
    }

    #[Test]
    public function setChannelModesBanStillSendsMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+b', ['*!*@bad.host']);

        self::assertSame([':001 MODE #test +b *!*@bad.host'], $this->written);
    }

    #[Test]
    public function setChannelModesPrefixModeStillSendsMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+o', ['001ABCD'], '001CSRV');

        self::assertSame([':001CSRV MODE #test +o 001ABCD'], $this->written);
    }

    #[Test]
    public function setChannelModesOwnerRankIsSkipped(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+q', ['001ABCD'], '001CSRV');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelModesOwnerRankRemovalIsSkipped(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '-q', ['001ABCD'], '001CSRV');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelModesOwnerRankWithoutParamIsSkipped(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+q');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelModesFiltersOwnerRankFromMixedBatch(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+qo', ['001ABCD', '001EFGH'], '001CSRV');

        self::assertSame([':001CSRV MODE #test +o 001EFGH'], $this->written);
    }

    #[Test]
    public function setChannelModesFiltersOwnerRankRemovalFromMixedBatch(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '-qv', ['001ABCD', '001EFGH'], '001CSRV');

        self::assertSame([':001CSRV MODE #test -v 001EFGH'], $this->written);
    }

    #[Test]
    public function setChannelModesRegisteredModeIsNoOp(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+r');
        $actions->setChannelModes('001', '#test', '-r');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelModesPermanentModeIsNoOp(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+P');
        $actions->setChannelModes('001', '#test', '-P');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelModesStripsRegisteredAndPermanentFromMixedBatch(): void
    {
        $actions = $this->createActions();

        $actions->setChannelModes('001', '#test', '+rPnt', [], '001CSRV');

        self::assertSame([':001CSRV MODE #test +nt'], $this->written);
    }

    #[Test]
    public function setChannelMemberModeOwnerIsNoOp(): void
    {
        $actions = $this->createActions();

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'q', true);
        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'q', false);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setChannelMemberModeSendsMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'o', true);

        self::assertSame([':001 MODE #test +o 001ABCD'], $this->written);
    }

    #[Test]
    public function setChannelMemberModeRemovesMode(): void
    {
        $actions = $this->createActions();

        $actions->setChannelMemberMode('001', '#test', '001ABCD', 'v', false);

        self::assertSame([':001 MODE #test -v 001ABCD'], $this->written);
    }

    #[Test]
    public function inviteUserToChannelSendsInvite(): void
    {
        $actions = $this->createActions();

        $actions->inviteUserToChannel('001', '#test', '001ABCD');

        self::assertSame([':001 INVITE 001ABCD #test'], $this->written);
    }

    #[Test]
    public function joinChannelAsServiceSendsJoinWithNonOwnerPrefix(): void
    {
        $actions = $this->createActions();

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'o');

        self::assertSame([
            ':001CSRV JOIN #test',
            ':001CSRV MODE #test +o 001CSRV',
        ], $this->written);
    }

    #[Test]
    public function joinChannelAsServiceSkipsOwnerPrefix(): void
    {
        $actions = $this->createActions();

        $actions->joinChannelAsService('001', '#test', '001CSRV', 'q');

        self::assertSame([':001CSRV JOIN #test'], $this->written);
    }

    #[Test]
    public function joinChannelAsServiceSkipsPrefixWhenEmpty(): void
    {
        $actions = $this->createActions();

        $actions->joinChannelAsService('001', '#test', '001CSRV', '');

        self::assertSame([':001CSRV JOIN #test'], $this->written);
    }

    #[Test]
    public function setChannelTopicPersistsToUdb(): void
    {
        $actions = $this->createActions();

        $actions->setChannelTopic('001', '#test', 'New topic', '001CSRV');

        self::assertSame([':001 DB * INS C::#test::topic :New topic'], $this->written);
    }

    #[Test]
    public function setChannelTopicClearsTopicWhenNull(): void
    {
        $actions = $this->createActions();

        $actions->setChannelTopic('001', '#test', null, '001CSRV');

        self::assertSame([':001 DB * DEL C::#test::topic'], $this->written);
    }

    #[Test]
    public function setChannelTopicClearsTopicWhenEmptyString(): void
    {
        $actions = $this->createActions();

        $actions->setChannelTopic('001', '#test', '', '001CSRV');

        self::assertSame([':001 DB * DEL C::#test::topic'], $this->written);
    }

    #[Test]
    public function kickFromChannelSendsKickCommand(): void
    {
        $actions = $this->createActions();

        $actions->kickFromChannel('001', '#test', '001ABCD', 'Kicked for abuse', '001CSRV');

        self::assertSame([':001CSRV KICK #test 001ABCD :Kicked for abuse'], $this->written);
    }

    #[Test]
    public function kickFromChannelUsesServerSidWhenServiceUidEmpty(): void
    {
        $actions = $this->createActions();

        $actions->kickFromChannel('001', '#test', '001ABCD', 'reason');

        self::assertSame([':001 KICK #test 001ABCD :reason'], $this->written);
    }

    #[Test]
    public function methodsDoNothingWhenDisconnected(): void
    {
        $connectionHolder = new ActiveConnectionHolder();

        $actions = new UnrealUdbProtocolServiceActions(
            $connectionHolder,
            $this->createUdbRecordWriter($connectionHolder),
        );

        $actions->setUserAccount('001', '001ABCD', 'TestAccount');
        $actions->setUserMode('001', '001ABCD', '+i');
        $actions->forceNick('001', '001ABCD', 'NewNick');
        $actions->killUser('001', '001ABCD', 'reason');
        $actions->setChannelModes('001', '#test', '+nt');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function addGlineWritesPatternReasonAndDuration(): void
    {
        $actions = $this->createActions();

        $actions->addGline('001', 'testuser', 'test.host', 3600, 'Test ban');

        self::assertSame([
            ':001 DB * INS K::G::testuser@test.host :Test ban',
            ':001 DB * INS K::G::testuser@test.host::reason :Test ban',
            ':001 DB * INS K::G::testuser@test.host::duration :*3600',
        ], $this->written);
    }

    #[Test]
    public function addGlinePermanentBanSkipsDuration(): void
    {
        $actions = $this->createActions();

        $actions->addGline('001', '*', '192.168.*', 0, 'Permanent ban');

        self::assertSame([
            ':001 DB * INS K::G::*@192.168.* :Permanent ban',
            ':001 DB * INS K::G::*@192.168.*::reason :Permanent ban',
        ], $this->written);
    }

    #[Test]
    public function removeGlineDeletesPattern(): void
    {
        $actions = $this->createActions();

        $actions->removeGline('001', 'testuser', 'test.host');

        self::assertSame([':001 DB * DEL K::G::testuser@test.host'], $this->written);
    }

    #[Test]
    public function removeGlineWithWildcards(): void
    {
        $actions = $this->createActions();

        $actions->removeGline('001', '*', '192.168.*');

        self::assertSame([':001 DB * DEL K::G::*@192.168.*'], $this->written);
    }

    #[Test]
    public function introducePseudoClientSendsUidCommand(): void
    {
        $actions = $this->createActions();

        $actions->introducePseudoClient('001', 'GlobalBot', 'global', 'services.red', '001Z00001', 'Global Message Bot');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:001 UID GlobalBot 1 \d+ global services\.red 001Z00001 0 \+BDIopqR services\.red \* \* \* :Global Message Bot$/', $this->written[0]);
    }

    #[Test]
    public function introducePseudoClientWithDifferentParams(): void
    {
        $actions = $this->createActions();

        $actions->introducePseudoClient('002', 'Announce', 'announce', 'irc.example.net', '002Z00005', 'Network Announcements');

        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 UID Announce 1 \d+ announce irc\.example\.net 002Z00005 0 \+BDIopqR irc\.example\.net \* \* \* :Network Announcements$/', $this->written[0]);
    }

    #[Test]
    public function quitPseudoClientSendsQuitCommand(): void
    {
        $actions = $this->createActions();

        $actions->quitPseudoClient('001', '001Z00001', 'Global message completed');

        self::assertSame([':001Z00001 QUIT :Global message completed'], $this->written);
    }

    #[Test]
    public function partChannelAsServiceSendsPartCommand(): void
    {
        $actions = $this->createActions();

        $actions->partChannelAsService('001', '#test', '001CSRV');

        self::assertSame([':001CSRV PART #test'], $this->written);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenSettingVhost(): void
    {
        $actions = $this->createActions();

        $actions->setUserVhost('001', '001ABCD', 'custom.vhost.net');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenSettingVhostWithSpaces(): void
    {
        $actions = $this->createActions();

        $actions->setUserVhost('001', '001ABCD', 'custom vhost with spaces');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setUserVhostIsNoOpWhenClearingVhost(): void
    {
        $actions = $this->createActions();

        $actions->setUserVhost('001', '001ABCD', '');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function introduceServiceSendsFormattedServiceIntroduction(): void
    {
        $actions = $this->createActions();

        $actions->introduceService('001', 'NickServ', 'NickServ', 'services.host', '001AAAAAA', 'Nickname Services', 'nickserv');

        self::assertCount(1, $this->written);
        self::assertStringContainsString(':001 UID NickServ 1', $this->written[0]);
        self::assertStringContainsString('001AAAAAA', $this->written[0]);
    }
}
