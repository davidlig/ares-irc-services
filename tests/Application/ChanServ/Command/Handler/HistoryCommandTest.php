<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\HistoryCommand;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelHistoryService;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelHistory;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(HistoryCommand::class)]
final class HistoryCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsHistory(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('HISTORY', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('history.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('history.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwoHundred(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(200, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('history.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsHistory(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(ChanServPermission::HISTORY, $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function usesLevelFounderReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->usesLevelFounder());
    }

    #[Test]
    public function getSubCommandHelpReturnsCorrectArray(): void
    {
        $cmd = $this->createCommand();

        $help = $cmd->getSubCommandHelp();

        self::assertCount(4, $help);
        self::assertSame('ADD', $help[0]['name']);
        self::assertSame('DEL', $help[1]['name']);
        self::assertSame('VIEW', $help[2]['name']);
        self::assertSame('CLEAR', $help[3]['name']);
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages);

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function executeDoesNothingWhenSenderNull(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');
        $historyRepo->expects(self::never())->method('deleteByChannelId');
        $historyRepo->expects(self::never())->method('deleteById');

        $context = $this->createContextWithNullSender(['#test', 'VIEW'], historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);
    }

    #[Test]
    public function executeWithInvalidChannelNameRepliesInvalidChannel(): void
    {
        $messages = [];
        $context = $this->createContext(['InvalidChannel', 'VIEW'], $messages);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('error.invalid_channel', $messages);
    }

    #[Test]
    public function executeWithNonexistentChannelRepliesNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('history.not_registered', $messages);
    }

    #[Test]
    public function executeAddWithNoMessageRepliesSyntaxError(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');

        $messages = [];
        $context = $this->createContext(['#test', 'ADD'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeAddWithWhitespaceMessageRepliesSyntaxError(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');

        $messages = [];
        $context = $this->createContext(['#test', 'ADD', '   '], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeAddSavesHistoryAndRepliesSuccess(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $savedHistory = null;
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $historyService = new ChannelHistoryService($historyRepo);

        $messages = [];
        $context = $this->createContext(['#test', 'ADD', 'Manual', 'note'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            $historyService,
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertNotNull($savedHistory);
        self::assertSame(1, $savedHistory->getChannelId());
        self::assertSame('HISTORY_ADD', $savedHistory->getAction());
        self::assertSame('OperUser', $savedHistory->getPerformedBy());
        self::assertSame('Manual note', $savedHistory->getMessage());
        self::assertSame('127.0.0.1', $savedHistory->getExtraData()['ip']);
        self::assertSame('i@h', $savedHistory->getExtraData()['host']);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame('Manual note', $auditData->reason);
    }

    #[Test]
    public function executeDelWithMissingIdRepliesSyntaxError(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['#test', 'DEL'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeDelWithInvalidIdRepliesInvalidId(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['#test', 'DEL', 'abc'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.del.invalid_id', $messages);
    }

    #[Test]
    public function executeDelWithNonexistentEntryRepliesNotFound(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn(null);

        $messages = [];
        $context = $this->createContext(['#test', 'DEL', '999'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.del.not_found', $messages);
    }

    #[Test]
    public function executeDelWithWrongChannelIdRepliesNotFound(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history = ChannelHistory::record(
            channelId: 999,
            action: 'TEST',
            performedBy: 'OtherUser',
            performedByNickId: null,
            message: 'Test message',
            extraData: [],
        );
        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn($history);

        $messages = [];
        $context = $this->createContext(['#test', 'DEL', '5'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.del.not_found', $messages);
    }

    #[Test]
    public function executeDelDeletesEntryAndRepliesSuccess(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history = ChannelHistory::record(
            channelId: 1,
            action: 'TEST',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'Test message',
            extraData: [],
        );

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn($history);
        $historyRepo->expects(self::once())->method('deleteById')->with(5);

        $messages = [];
        $context = $this->createContext(['#test', 'DEL', '5'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.del.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame(['entry_id' => 5], $auditData->extra);
    }

    #[Test]
    public function executeViewWithNoHistoryRepliesNoEntries(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(0);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.no_entries', $messages);
    }

    #[Test]
    public function executeWithPageNumber(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(100);
        $historyRepo->expects(self::once())
            ->method('findByChannelId')
            ->with(1, 5, 5);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW', '2'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            historyViewLimit: 5,
        );
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewWithPageNumberUsesDefaultLimit(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(100);
        $historyRepo->method('findByChannelId')->willReturn([]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW', '2'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewWithInvalidPageNumberUsesPageOne(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(100);
        $historyRepo->expects(self::once())
            ->method('findByChannelId')
            ->with(1, 5, 0);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW', '-5'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            historyViewLimit: 5,
        );
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewShowsPageHintWhenMorePagesExist(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(10);
        $historyRepo->method('findByChannelId')->willReturn([]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW', '1'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            historyViewLimit: 5,
        );
        $cmd->execute($context);

        self::assertContains('history.view.page_hint', $messages);
    }

    #[Test]
    public function executeViewDisplaysHistoryEntriesWithExtraData(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'SUSPEND',
            performedBy: 'OperUser',
            performedByNickId: 2,
            message: 'history.message.suspended',
            extraData: ['duration' => '7d', 'expires_at' => '2024-01-22', 'ip' => '192.168.1.1', 'host' => 'oper@test'],
        );
        $history1->setId(1);

        $history2 = ChannelHistory::record(
            channelId: 1,
            action: 'SET_EMAIL',
            performedBy: 'User',
            performedByNickId: null,
            message: 'history.message.email_changed',
            extraData: ['old_founder' => 'OldNick', 'new_founder' => 'NewNick'],
        );
        $history2->setId(2);

        $history3 = ChannelHistory::record(
            channelId: 1,
            action: 'RECOVER',
            performedBy: 'Admin',
            performedByNickId: 3,
            message: 'Custom message not a translation key',
            extraData: ['mask' => '*@bad.host', 'level' => '10', 'target_nickname' => 'BadUser'],
        );
        $history3->setId(3);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(3);
        $historyRepo->method('findByChannelId')->willReturn([$history1, $history2, $history3]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewDisplaysHistoryWithUnknownOperator(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'TEST',
            performedBy: 'OldUser',
            performedByNickId: 999,
            message: 'Test message',
            extraData: [],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo, nickRepo: $nickRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $nickRepo,
        );
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewDisplaysHistoryWithKnownOperator(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $operNick = $this->createNickWithId('KnownOper', 999);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($operNick);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'TEST',
            performedBy: 'KnownOper',
            performedByNickId: 999,
            message: 'Test message',
            extraData: [],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo, nickRepo: $nickRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $nickRepo,
        );
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewAllShowsAllEntries(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'SUSPEND',
            performedBy: 'OperUser',
            performedByNickId: 2,
            message: 'Test',
            extraData: [],
        );

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->expects(self::once())
            ->method('findByChannelId')
            ->with(1, null, 0);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW', 'ALL'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeClearDeletesAllEntries(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('deleteByChannelId')->with(1)->willReturn(5);

        $messages = [];
        $context = $this->createContext(['#test', 'CLEAR'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.clear.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('#test', $auditData->target);
        self::assertSame(['count' => 5], $auditData->extra);
    }

    #[Test]
    public function executeWithUnknownActionRepliesSyntaxError(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['#test', 'UNKNOWN'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeAddWithEmptyIp(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $savedHistory = null;
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['#test', 'ADD', 'Test', 'note'], $messages, '', $channelRepo, $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertSame('*', $savedHistory->getExtraData()['ip']);
    }

    #[Test]
    public function executeAddWithAsteriskIp(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $savedHistory = null;
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['#test', 'ADD', 'Test', 'note'], $messages, '*', $channelRepo, $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertSame('*', $savedHistory->getExtraData()['ip']);
    }

    #[Test]
    public function executeAddWithInvalidIpBase64(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $savedHistory = null;
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['#test', 'ADD', 'Test', 'note'], $messages, 'invalid!base64', $channelRepo, $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertSame('invalid!base64', $savedHistory->getExtraData()['ip']);
    }

    #[Test]
    public function executeAddWithValidBase64ButInvalidIpBinaryFallsBackToRaw(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $savedHistory = null;
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (ChannelHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['#test', 'ADD', 'Test', 'note'], $messages, 'AQID', $channelRepo, $historyRepo);

        $cmd = new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertSame('AQID', $savedHistory->getExtraData()['ip']);
    }

    #[Test]
    public function executeViewDisplaysEntryWithSuccessorExtraData(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'SUCCESSOR',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'history.message.successor_changed',
            extraData: ['old_successor' => 'OldSucc', 'new_successor' => 'NewSucc'],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewDisplaysEntryWithNullOldFounderExtraData(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'FOUNDER',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'history.message.founder_changed',
            extraData: ['old_founder' => null, 'new_founder' => 'NewFounder'],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewTranslatesAccessAddMessageWithTargetAndLevel(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'ACCESS_ADD',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'history.message.access_add',
            extraData: ['target_nickname' => 'TargetUser', 'level' => '50'],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewTranslatesAkickAddMessageWithMask(): void
    {
        $channel = $this->createChannelWithId('#test', 1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $history1 = ChannelHistory::record(
            channelId: 1,
            action: 'AKICK_ADD',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'history.message.akick_add',
            extraData: ['mask' => '*!*@bad.host'],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('countByChannelId')->willReturn(1);
        $historyRepo->method('findByChannelId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['#test', 'VIEW'], $messages, channelRepo: $channelRepo, historyRepo: $historyRepo);

        $cmd = $this->createCommandWithRepos($channelRepo, $historyRepo);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    private function createCommand(): HistoryCommand
    {
        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);

        return new HistoryCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
    }

    private function createCommandWithRepos(
        RegisteredChannelRepositoryInterface $channelRepo,
        ChannelHistoryRepositoryInterface $historyRepo,
    ): HistoryCommand {
        return new HistoryCommand(
            $channelRepo,
            $historyRepo,
            new ChannelHistoryService($historyRepo),
            $this->createStub(RegisteredNickRepositoryInterface::class),
        );
    }

    private function createSender(string $ipBase64 = 'fwAAAQ=='): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', $ipBase64, false, true, 'SID1', 'h', 'o', '');
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }

    private function createChannelWithId(string $channelName, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($channelName, 1, 'Test channel');

        $reflection = new ReflectionClass(RegisteredChannel::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, $id);

        return $channel;
    }

    private function createContext(
        array $args,
        array &$messages,
        ?RegisteredChannelRepositoryInterface $channelRepo = null,
        ?ChannelHistoryRepositoryInterface $historyRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $targetUid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $historyRepoFinal = $historyRepo ?? $this->createStub(ChannelHistoryRepositoryInterface::class);
        $nickRepoFinal = $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class);

        return new ChanServContext(
            $this->createSender(),
            $this->createNickWithId('OperUser', 2),
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithNullSender(array $args, ChannelHistoryRepositoryInterface $historyRepo): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            null,
            null,
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithIp(
        array $args,
        array &$messages,
        string $ipBase64,
        RegisteredChannelRepositoryInterface $channelRepo,
        ChannelHistoryRepositoryInterface $historyRepo,
    ): ChanServContext {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $targetUid, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ChanServContext(
            $this->createSender($ipBase64),
            $this->createNickWithId('OperUser', 2),
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('chanserv');
        $provider->method('getNickname')->willReturn('ChanServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
