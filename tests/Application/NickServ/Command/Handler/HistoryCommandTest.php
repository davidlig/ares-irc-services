<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\HistoryCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickHistoryService;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
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

        self::assertSame(NickServPermission::HISTORY, $cmd->getRequiredPermission());
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
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        self::assertNull($cmd->getAuditData($this->createStub(NickServContext::class)));
    }

    #[Test]
    public function executeWithNonexistentNickRepliesNotRegistered(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW'], $messages, nickRepo: $nickRepo);

        $cmd = $this->createCommand();
        $cmd->execute($context);

        self::assertContains('history.not_registered', $messages);
    }

    #[Test]
    public function executeAddWithNoMessageRepliesSyntaxError(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');

        $messages = [];
        $context = $this->createContext(['TestNick', 'ADD'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeAddWithWhitespaceMessageRepliesSyntaxError(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');

        $messages = [];
        $context = $this->createContext(['TestNick', 'ADD', '   '], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeAddSavesHistoryAndRepliesSuccess(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $savedHistory = null;
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (NickHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $historyService = new NickHistoryService($historyRepo);

        $messages = [];
        $context = $this->createContext(['TestNick', 'ADD', 'Manual', 'note'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $historyService);
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertNotNull($savedHistory);
        self::assertSame(1, $savedHistory->getNickId());
        self::assertSame('HISTORY_ADD', $savedHistory->getAction());
        self::assertSame('OperUser', $savedHistory->getPerformedBy());
        self::assertSame('Manual note', $savedHistory->getMessage());

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertSame('Manual note', $auditData->reason);
    }

    #[Test]
    public function executeDelWithMissingIdRepliesSyntaxError(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['TestNick', 'DEL'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeDelWithInvalidIdRepliesInvalidId(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['TestNick', 'DEL', 'abc'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.del.invalid_id', $messages);
    }

    #[Test]
    public function executeDelWithNonexistentEntryRepliesNotFound(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn(null);

        $messages = [];
        $context = $this->createContext(['TestNick', 'DEL', '999'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.del.not_found', $messages);
    }

    #[Test]
    public function executeDelWithWrongNickIdRepliesNotFound(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $history = NickHistory::record(
            nickId: 999,
            action: 'TEST',
            performedBy: 'OtherUser',
            performedByNickId: null,
            message: 'Test message',
            extraData: [],
        );
        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn($history);

        $messages = [];
        $context = $this->createContext(['TestNick', 'DEL', '5'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.del.not_found', $messages);
    }

    #[Test]
    public function executeDelDeletesEntryAndRepliesSuccess(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $history = NickHistory::record(
            nickId: 1,
            action: 'TEST',
            performedBy: 'OperUser',
            performedByNickId: null,
            message: 'Test message',
            extraData: [],
        );

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->method('findById')->willReturn($history);
        $historyRepo->expects(self::once())->method('deleteById')->with(5);

        $messages = [];
        $context = $this->createContext(['TestNick', 'DEL', '5'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.del.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertSame(['entry_id' => 5], $auditData->extra);
    }

    #[Test]
    public function executeViewWithNoHistoryRepliesNoEntries(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(0);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.view.no_entries', $messages);
    }

    #[Test]
    public function executeViewWithPageNumber(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(100);
        $historyRepo->expects(self::once())
            ->method('findByNickId')
            ->with(1, 5, 5);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW', '2'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo), historyViewLimit: 5);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewWithPageNumberUsesDefaultLimit(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(100);
        $historyRepo->method('findByNickId')->willReturn([]);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW', '2'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewWithInvalidPageNumberUsesPageOne(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(100);
        $historyRepo->expects(self::once())
            ->method('findByNickId')
            ->with(1, 5, 0);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW', '-5'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo), historyViewLimit: 5);
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewShowsPageHintWhenMorePagesExist(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(10);
        $historyRepo->method('findByNickId')->willReturn([]);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW', '1'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo), historyViewLimit: 5);
        $cmd->execute($context);

        self::assertContains('history.view.page_hint', $messages);
    }

    #[Test]
    public function executeViewDisplaysHistoryEntriesWithExtraData(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $history1 = NickHistory::record(
            nickId: 1,
            action: 'SUSPEND',
            performedBy: 'OperUser',
            performedByNickId: 2,
            message: 'history.message.suspended',
            extraData: ['duration' => '7d', 'expires_at' => '2024-01-22', 'ip' => '192.168.1.1', 'host' => 'oper@test'],
        );
        $history1->setId(1);

        $history2 = NickHistory::record(
            nickId: 1,
            action: 'SET_EMAIL',
            performedBy: 'User',
            performedByNickId: null,
            message: 'history.message.email_changed',
            extraData: ['old_email' => 'old@test.com', 'new_email' => 'new@test.com'],
        );
        $history2->setId(2);

        $history3 = NickHistory::record(
            nickId: 1,
            action: 'RECOVER',
            performedBy: 'Admin',
            performedByNickId: 3,
            message: 'Custom message not a translation key',
            extraData: ['method' => 'email'],
        );
        $history3->setId(3);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(3);
        $historyRepo->method('findByNickId')->willReturn([$history1, $history2, $history3]);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewDisplaysHistoryWithUnknownOperator(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);
        $nickRepo->method('findById')->willReturn(null);

        $history1 = NickHistory::record(
            nickId: 1,
            action: 'TEST',
            performedBy: 'OldUser',
            performedByNickId: 999,
            message: 'Test message',
            extraData: [],
        );
        $history1->setId(1);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(1);
        $historyRepo->method('findByNickId')->willReturn([$history1]);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeViewAllShowsAllEntries(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $history1 = NickHistory::record(
            nickId: 1,
            action: 'SUSPEND',
            performedBy: 'OperUser',
            performedByNickId: 2,
            message: 'Test',
            extraData: [],
        );

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->method('countByNickId')->willReturn(1);
        $historyRepo->expects(self::once())
            ->method('findByNickId')
            ->with(1, null, 0);

        $messages = [];
        $context = $this->createContext(['TestNick', 'VIEW', 'ALL'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.view.header', $messages);
    }

    #[Test]
    public function executeClearDeletesAllEntries(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('deleteByNickId')->with(1)->willReturn(5);

        $messages = [];
        $context = $this->createContext(['TestNick', 'CLEAR'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.clear.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertSame(['count' => 5], $auditData->extra);
    }

    #[Test]
    public function executeWithUnknownActionRepliesSyntaxError(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);

        $messages = [];
        $context = $this->createContext(['TestNick', 'UNKNOWN'], $messages, nickRepo: $nickRepo, historyRepo: $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeDoesNothingWhenSenderNull(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('save');
        $historyRepo->expects(self::never())->method('deleteByNickId');
        $historyRepo->expects(self::never())->method('deleteById');

        $context = $this->createContextWithNullSender(['TestNick', 'VIEW'], historyRepo: $historyRepo);

        $cmd = new HistoryCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $historyRepo,
            $this->createNickHistoryService($historyRepo),
        );
        $cmd->execute($context);
    }

    private function createCommand(): HistoryCommand
    {
        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);

        return new HistoryCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $historyRepo,
            $this->createNickHistoryService($historyRepo),
        );
    }

    private function createNickHistoryService(NickHistoryRepositoryInterface $repo): NickHistoryService
    {
        return new NickHistoryService($repo);
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'AQ==', false, true, 'SID1', 'h', 'o', '');
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

    private function createContext(
        array $args,
        array &$messages,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?NickHistoryRepositoryInterface $historyRepo = null,
    ): NickServContext {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $historyRepoFinal = $historyRepo ?? $this->createStub(NickHistoryRepositoryInterface::class);

        return new NickServContext(
            $this->createSender(),
            $this->createNickWithId('OperUser', 2),
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithNullSender(array $args, NickHistoryRepositoryInterface $historyRepo): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            null,
            null,
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function executeAddWithEmptyIp(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $savedHistory = null;
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (NickHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['TestNick', 'ADD', 'Test', 'note'], $messages, '', $nickRepo, $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
    }

    #[Test]
    public function executeAddWithInvalidIpBase64(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $savedHistory = null;
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (NickHistory $h) use (&$savedHistory): void {
                $savedHistory = $h;
            });

        $messages = [];
        $context = $this->createContextWithIp(['TestNick', 'ADD', 'Test', 'note'], $messages, 'invalid!base64', $nickRepo, $historyRepo);

        $cmd = new HistoryCommand($nickRepo, $historyRepo, $this->createNickHistoryService($historyRepo));
        $cmd->execute($context);

        self::assertContains('history.add.success', $messages);
        self::assertSame('invalid!base64', $savedHistory->getExtraData()['ip']);
    }

    private function createContextWithIp(
        array $args,
        array &$messages,
        string $ip,
        RegisteredNickRepositoryInterface $nickRepo,
        NickHistoryRepositoryInterface $historyRepo,
    ): NickServContext {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            new SenderView('UID1', 'OperUser', 'i', 'h', 'c', $ip, false, true, 'SID1', 'h', 'o', ''),
            $this->createNickWithId('OperUser', 2),
            'HISTORY',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
