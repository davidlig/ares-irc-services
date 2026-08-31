<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\Handler\RawCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Application\Port\UdbRawCommandResult;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RawCommand::class)]
final class RawCommandTest extends TestCase
{
    private function createAccessHelper(): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslationInterface $translator,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'RAW',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            $this->createAccessHelper(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    private function createConnectionHolder(
        string $protocol = 'unrealudb',
        bool $connected = true,
    ): ActiveConnectionHolderInterface {
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getProtocolName')->willReturn($protocol);
        $holder->method('getProtocolModule')->willReturn($module);
        $holder->method('isConnected')->willReturn($connected);
        $holder->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $holder;
    }

    private function createCommand(
        ?ActiveConnectionHolderInterface $connectionHolder = null,
        ?UdbRawCommandHandlerInterface $udbCommands = null,
    ): RawCommand {
        return new RawCommand(
            $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            $udbCommands,
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    /** @var list<string> */
    private array $written = [];

    #[Test]
    public function getNameReturnsRaw(): void
    {
        self::assertSame('RAW', $this->createCommand()->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        self::assertSame(1, $this->createCommand()->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.syntax', $this->createCommand()->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.help', $this->createCommand()->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        self::assertSame(40, $this->createCommand()->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        self::assertSame('raw.short', $this->createCommand()->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        self::assertTrue($this->createCommand()->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsRaw(): void
    {
        self::assertSame(OperServPermission::RAW, $this->createCommand()->getRequiredPermission());
    }

    #[Test]
    public function nullSenderReturnsEarly(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $translator = $this->createStub(TranslationInterface::class);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(null, ['test'], $notifier, $translator));
    }

    #[Test]
    public function emptyLineRepliesEmpty(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext($this->createSender(), ['   '], $notifier, $translator));

        self::assertStringContainsString('raw.empty', $messages[0]);
    }

    #[Test]
    public function lineTooLongRepliesTooLong(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($this->createSender(), [str_repeat('x', 511)], $notifier, $translator));

        self::assertStringContainsString('raw.too_long', $messages[0]);
    }

    #[Test]
    public function notConnectedRepliesNotConnected(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('isConnected')->willReturn(false);

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($this->createSender(), [':0A0BBBBBB', 'MODE', '#opers', '+q', '994AAAAAA'], $notifier, $translator));

        self::assertStringContainsString('raw.not_connected', $messages[0]);
    }

    #[Test]
    public function successWritesLineAndSetsAuditData(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();
        $sender = $this->createSender();

        $connectionHolder = $this->createMock(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('isConnected')->willReturn(true);
        $connectionHolder->expects(self::once())->method('writeLine')->with(':0A0BBBBBB MODE #opers +q 994AAAAAA');

        $cmd = $this->createCommand($connectionHolder);
        $cmd->execute($this->createContext($sender, [':0A0BBBBBB', 'MODE', '#opers', '+q', '994AAAAAA'], $notifier, $translator));

        self::assertStringContainsString('raw.done', $messages[0]);

        $auditData = $cmd->getAuditData($this->createContext($sender, [], $notifier, $translator));
        self::assertNotNull($auditData);
        self::assertSame(':0A0BBBBBB MODE #opers +q 994AAAAAA', $auditData->target);
        self::assertSame('Executed by TestUser', $auditData->reason);
    }

    // ---------- UDB interception ----------

    #[Test]
    public function udbInsIsInterceptedInsteadOfWrittenRaw(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $results = [
            UdbRawCommandResult::success('DB * INS S::propagator "hub2.davidlig.net"'),
        ];
        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::once())->method('ins')->with('S::propagator', 'hub2.davidlig.net')
            ->willReturn(array_shift($results));

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator', '"hub2.davidlig.net"'], $notifier, $translator));

        self::assertSame([], $this->written);
        self::assertStringContainsString('raw.udb.done', $messages[0]);
    }

    #[Test]
    public function udbInsAcceptsTrailingColonAndMultiWordValues(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::once())->method('ins')->with('K::G::*@bad.host::reason', 'spam bots here')
            ->willReturn(UdbRawCommandResult::success('DB * INS K::G::*@bad.host::reason spam bots here'));

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'K::G::*@bad.host::reason', ':spam', 'bots', 'here'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.done', $messages[0]);
    }

    #[Test]
    public function udbDelIsIntercepted(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::once())->method('del')->with('N::badnick')
            ->willReturn(UdbRawCommandResult::success('DB * DEL N::badnick'));

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'DEL', 'N::badnick'], $notifier, $translator));

        self::assertSame([], $this->written);
        self::assertStringContainsString('raw.udb.done', $messages[0]);
    }

    #[Test]
    public function udbInsWithoutValueRepliesSyntax(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.syntax', $messages[0]);
    }

    #[Test]
    public function udbDelWithValueRepliesSyntax(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('del');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'DEL', 'N::badnick', 'extra'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.syntax', $messages[0]);
    }

    #[Test]
    public function udbNonBroadcastTargetIsRejected(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '001', 'INS', 'S::propagator', 'x'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.target', $messages[0]);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function udbDropAndOptAreUnsupported(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');
        $udb->expects(self::never())->method('del');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'DRP', 'S'], $notifier, $translator));
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'OPT', 'S'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.unsupported', $messages[0]);
        self::assertStringContainsString('raw.udb.unsupported', $messages[1]);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function udbHandlerErrorsAreRepliedWithTheErrorKey(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::once())->method('ins')->with('S::propagator', 'x')
            ->willReturn(UdbRawCommandResult::error('raw.udb.invalid_value', ['%path%' => 'S::propagator']));

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator', 'x'], $notifier, $translator));

        self::assertStringContainsString('raw.udb.invalid_value', $messages[0]);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function udbSuccessfulMutationStoresRedactedAuditData(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();
        $sender = $this->createSender();

        $udb = $this->createStub(UdbRawCommandHandlerInterface::class);
        $udb->method('ins')->willReturn(UdbRawCommandResult::success('DB * INS N::nick::pass <redacted>'));

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($sender, ['DB', '*', 'INS', 'N::nick::pass', 'secret'], $notifier, $translator));

        $auditData = $cmd->getAuditData($this->createContext($sender, [], $notifier, $translator));
        self::assertNotNull($auditData);
        self::assertSame('DB * INS N::nick::pass <redacted>', $auditData->target);
        self::assertSame('Executed by TestUser', $auditData->reason);
    }

    #[Test]
    public function otherDbFramesKeepTheClassicRawBehavior(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');
        $udb->expects(self::never())->method('del');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '001', 'HEL', '4', 'x'], $notifier, $translator));

        self::assertSame(['DB 001 HEL 4 x'], $this->written);
    }

    #[Test]
    public function nonDbLinesPassThroughEvenWithUdbHandler(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');
        $udb->expects(self::never())->method('del');

        $cmd = $this->createCommand($this->createConnectionHolder(), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['NOTICE', '$*', ':hi'], $notifier, $translator));

        self::assertSame(['NOTICE $* :hi'], $this->written);
    }

    #[Test]
    public function nonUdbProtocolsKeepTheClassicRawBehavior(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $udb = $this->createMock(UdbRawCommandHandlerInterface::class);
        $udb->expects(self::never())->method('ins');
        $udb->expects(self::never())->method('del');

        $cmd = $this->createCommand($this->createConnectionHolder('unreal'), $udb);
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator', 'x'], $notifier, $translator));

        self::assertSame(['DB * INS S::propagator x'], $this->written);
        self::assertStringContainsString('raw.done', $messages[0]);
    }

    #[Test]
    public function missingUdbHandlerKeepsTheClassicRawBehavior(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $cmd = $this->createCommand($this->createConnectionHolder());
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator', 'x'], $notifier, $translator));

        self::assertSame(['DB * INS S::propagator x'], $this->written);
        self::assertStringContainsString('raw.done', $messages[0]);
    }

    #[Test]
    public function withoutProtocolModuleTheClassicRawBehaviorApplies(): void
    {
        $messages = [];
        $notifier = $this->stubNotifier($messages);
        $translator = $this->stubTranslator();

        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('isConnected')->willReturn(true);
        $holder->method('getProtocolModule')->willReturn(null);
        $holder->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $cmd = $this->createCommand($holder, $this->createStub(UdbRawCommandHandlerInterface::class));
        $cmd->execute($this->createContext($this->createSender(), ['DB', '*', 'INS', 'S::propagator', 'x'], $notifier, $translator));

        self::assertSame(['DB * INS S::propagator x'], $this->written);
    }

    // ---------- Helpers ----------

    private function stubNotifier(array &$messages): OperServNotifierInterface
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');

        return $notifier;
    }

    private function stubTranslator(): TranslationInterface
    {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
