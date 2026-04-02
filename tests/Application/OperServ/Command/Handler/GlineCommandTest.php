<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\GlineCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(GlineCommand::class)]
final class GlineCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsGline(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('GLINE', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('gline.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('gline.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwenty(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(20, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('gline.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsExpectedStructure(): void
    {
        $cmd = $this->createCommand();
        $subCommands = $cmd->getSubCommandHelp();

        self::assertCount(3, $subCommands);
        self::assertSame('ADD', $subCommands[0]['name']);
        self::assertSame('DEL', $subCommands[1]['name']);
        self::assertSame('LIST', $subCommands[2]['name']);
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $cmd = $this->createCommand();
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsGline(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(OperServPermission::GLINE, $cmd->getRequiredPermission());
    }

    #[Test]
    public function unknownSubcommandReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['INVALID'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.unknown_sub', $messages[0] ?? '');
    }

    #[Test]
    public function addMissingArgsReturnsSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '*@host'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('error.syntax', $messages[0] ?? '');
    }

    #[Test]
    public function addInvalidMaskReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', 'bad!user@host', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.invalid_mask', $messages[0] ?? '');
    }

    #[Test]
    public function addGlobalMaskReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '*!*@*', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.invalid_mask', $messages[0] ?? '');
    }

    #[Test]
    public function addGlobalUserHostMaskReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '*@*', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.global_mask', $messages[0] ?? '');
    }

    #[Test]
    public function addDangerousMaskReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '*@abc', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.dangerous_mask', $messages[0] ?? '');
    }

    #[Test]
    public function addInvalidExpiryReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', 'invalid', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.invalid_expiry', $messages[0] ?? '');
    }

    #[Test]
    public function addAlreadyExistsReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $existingGline = Gline::create('*@192.168.*', 1, 'Test', new DateTimeImmutable('+1 day'));
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn($existingGline);
        $glineRepo->method('countAll')->willReturn(0);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['ADD', '*@192.168.*', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.already_exists', $messages[0] ?? '');
    }

    #[Test]
    public function addMaxEntriesReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('countAll')->willReturn(1000);

        $cmd = $this->createCommandWithRepo($glineRepo, 1000);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.max_entries', $messages[0] ?? '');
    }

    #[Test]
    public function addSuccessSavesAndSends(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addWithNicknameResolvesToUserHost(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(
            new SenderView('UID123', 'BadUser', 'badident', 'badhost1234.com', 'clines', 'test==', false, true, 'SID1', 'h', 'o', 'badhost1234.com')
        );

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', 'badident', 'badhost1234.com', self::anything(), 'Test reason');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = new GlineCommand(
            $glineRepo,
            $userLookup,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $accessHelper,
            $connectionHolder,
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['ADD', 'BadUser', '1d', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addWithNicknameNotFoundReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $cmd = new GlineCommand(
            $this->createStub(GlineRepositoryInterface::class),
            $userLookup,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['ADD', 'OfflineUser', '1d', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.user_not_found', $messages[0] ?? '');
    }

    #[Test]
    public function delMissingArgsReturnsSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['DEL'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('error.syntax', $messages[0] ?? '');
    }

    #[Test]
    public function delNotFoundReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('findAll')->willReturn([]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['DEL', '*@host.com'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.not_found', $messages[0] ?? '');
    }

    #[Test]
    public function delSuccessRemovesAndSends(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host1234.com', 1, 'Test');
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn($gline);
        $glineRepo->expects(self::once())->method('remove');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('removeGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['DEL', '*@host1234.com'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.del.done', $messages[0] ?? '');
    }

    #[Test]
    public function listEmptyReturnsEmptyMessage(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.empty', $messages[0] ?? '');
    }

    #[Test]
    public function listReturnsEntries(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline1 = Gline::create('*@host1.com', null, 'Reason 1');
        $gline2 = Gline::create('*@host2.com', null, 'Reason 2');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline1, $gline2]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.header', $messages[0] ?? '');
        self::assertStringContainsString('gline.list.entry', $messages[1] ?? '');
    }

    #[Test]
    public function listWithFilterUsesPattern(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMaskPattern')->with('badisp');
        $glineRepo->method('findByMaskPattern')->willReturn([]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['LIST', 'badisp'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);
    }

    #[Test]
    public function addPermanentGline(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', '*', 'host1234.com', 0, 'Test reason');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '0', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addGlineWithHoursExpiry(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '2h', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addGlineWithMinutesExpiry(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '30m', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addRemovesExpiredGlineBeforeAddingNew(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $expiredGline = Gline::create('*@host1234.com', 1, 'Old', new DateTimeImmutable('-1 day'));
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn($expiredGline);
        $glineRepo->expects(self::once())->method('remove');
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'New reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addNoModuleLogsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'Test'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addNoServerSidLogsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findByMask')->willReturn(null);
        $glineRepo->expects(self::once())->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'Test'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function delNoModuleLogsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host1234.com', 1, 'Test');
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn($gline);
        $glineRepo->expects(self::once())->method('remove');

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['DEL', '*@host1234.com'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.del.done', $messages[0] ?? '');
    }

    #[Test]
    public function delNoServerSidLogsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host1234.com', 1, 'Test');
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn($gline);
        $glineRepo->expects(self::once())->method('remove');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['DEL', '*@host1234.com'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.del.done', $messages[0] ?? '');
    }

    #[Test]
    public function listWithExpiryAndNullReason(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $expiresAt = new DateTimeImmutable('+1 day');
        $gline1 = Gline::create('*@host1.com', null, null, $expiresAt);
        $gline2 = Gline::create('*@host2.com', null, 'With reason');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline1, $gline2]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.header', $messages[0] ?? '');
        self::assertCount(3, $messages);
    }

    #[Test]
    public function delEmptyMaskReturnsSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['DEL', '   '], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('error.syntax', $messages[0] ?? '');
    }

    #[Test]
    public function addEmptyMaskReturnsSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '   ', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('error.syntax', $messages[0] ?? '');
    }

    #[Test]
    public function addEmptyReasonReturnsSyntaxError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', '   '], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('error.syntax', $messages[0] ?? '');
    }

    #[Test]
    public function nullSenderReturnsEarly(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $accessHelper = $this->createAccessHelper(false);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCommand();
        $context = $this->createContext(null, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);
    }

    #[Test]
    public function addMaskMatchingRootUserReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(false);
        $registry = new OperServCommandRegistry([]);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(
            new SenderView('UIDROOT', 'TestUser', 'ident', 'host999.com', 'clines', 'test==', false, true, 'SID1', 'h', 'o', 'host999.com')
        );

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        // TestUser is root, and is online with ident@host999.com
        $rootRegistry = new RootUserRegistry('TestUser');
        $cmd = new GlineCommand(
            $this->createStub(GlineRepositoryInterface::class),
            $userLookup,
            $nickRepo,
            $ircopRepo,
            $rootRegistry,
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        // Try to GLINE the host where root is connected
        $context = $this->createContext($sender, ['ADD', '*@host999.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.protected_user', $messages[0] ?? '');
    }

    #[Test]
    public function addRootUserNotOnlineSkipsProtection(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null); // Root user not online

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $rootRegistry = new RootUserRegistry('TestUser');

        $cmd = new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            $rootRegistry,
            $accessHelper,
            $connectionHolder,
            new NullLogger(),
            1000,
        );

        // GLINE allowed because root user TestUser is not online
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addGlineWithNickUserHostFormatMatchesCorrectly(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getNickId')->willReturn(42);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getNickname')->willReturn('IrcopNick');
        $nick->method('getId')->willReturn(42);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        // IRCop is online with nick!ident@host format
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(
            new SenderView('UIDIRCOP', 'IrcopNick', 'myident', 'specialhost.net', 'clines', 'test==', false, true, 'SID1', 'h', 'o', 'specialhost.net')
        );

        $cmd = new GlineCommand(
            $this->createStub(GlineRepositoryInterface::class),
            $userLookup,
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        // GLINE with user@host format should match the IRCop's ident@host
        $context = $this->createContext($sender, ['ADD', 'myident@specialhost.net', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.protected_user', $messages[0] ?? '');
    }

    #[Test]
    public function addMaskMatchingIrcopReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(false);
        $registry = new OperServCommandRegistry([]);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getNickId')->willReturn(42);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getNickname')->willReturn('IrcopNick');
        $nick->method('getId')->willReturn(42);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(
            new SenderView('UIDIRCOP', 'IrcopNick', 'ident123', 'host456.com', 'clines', 'test==', false, true, 'SID1', 'h', 'o', 'host456.com')
        );

        $cmd = new GlineCommand(
            $this->createStub(GlineRepositoryInterface::class),
            $userLookup,
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['ADD', '*@host456.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.protected_user', $messages[0] ?? '');
    }

    #[Test]
    public function delByNumericIndexRemovesGline(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline1 = Gline::create('*@host1.com', 1, 'Reason 1');
        $gline2 = Gline::create('*@host2.com', 2, 'Reason 2');

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline1, $gline2]);
        $glineRepo->expects(self::once())->method('remove');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('removeGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['DEL', '1'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.del.done', $messages[0] ?? '');
    }

    #[Test]
    public function delByInvalidNumericIndexReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([Gline::create('*@host.com', 1, 'Test')]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['DEL', '99'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.not_found', $messages[0] ?? '');
    }

    #[Test]
    public function delByZeroNumericIndexReturnsError(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['DEL', '0'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.not_found', $messages[0] ?? '');
    }

    #[Test]
    public function listWithNullCreatorShowsUnknown(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host.com', null, 'Test reason');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline]);

        $cmd = $this->createCommandWithRepo($glineRepo);
        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.header', $messages[0] ?? '');
        self::assertStringContainsString('gline.list.entry', $messages[1] ?? '');
    }

    #[Test]
    public function listWithCreatorShowsNickName(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host.com', 42, 'Test reason');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getNickname')->willReturn('CreatorNick');

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        $cmd = new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.header', $messages[0] ?? '');
        self::assertStringContainsString('gline.list.entry', $messages[1] ?? '');
    }

    #[Test]
    public function listWithCreatorNotFoundShowsUnknown(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host.com', 999, 'Test reason');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findAll')->willReturn([$gline]);

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        $cmd = new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.list.header', $messages[0] ?? '');
        self::assertStringContainsString('gline.list.entry', $messages[1] ?? '');
    }

    #[Test]
    public function addIrcopNickNotFoundSkipsProtection(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getNickId')->willReturn(99);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = new GlineCommand(
            $glineRepo,
            $this->createStub(NetworkUserLookupPort::class),
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $connectionHolder,
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function addIrcopUserNotOnlineSkipsProtection(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getNickId')->willReturn(42);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getNickname')->willReturn('IrcopNick');
        $nick->method('getId')->willReturn(42);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            new RootUserRegistry(''),
            $accessHelper,
            $connectionHolder,
            new NullLogger(),
            1000,
        );

        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        self::assertStringContainsString('gline.add.done', $messages[0] ?? '');
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();
        $context = $this->createContext($this->createSender(), ['LIST'], $this->createStub(OperServNotifierInterface::class), $this->createStub(TranslatorInterface::class), new OperServCommandRegistry([]), $this->createAccessHelper(false));

        self::assertNull($cmd->getAuditData($context));
    }

    #[Test]
    public function getAuditDataReturnsDataAfterAdd(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn(null);
        $glineRepo->method('countAll')->willReturn(0);
        $glineRepo->expects(self::once())->method('save');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['ADD', '*@host1234.com', '1d', 'Test reason'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertNotNull($auditData);
        self::assertSame('*@host1234.com', $auditData->target);
        self::assertSame('Test reason', $auditData->reason);
        self::assertSame(['duration' => '1d'], $auditData->extra);
    }

    #[Test]
    public function getAuditDataReturnsDataAfterDel(): void
    {
        $sender = $this->createSender();
        $messages = [];
        $notifier = $this->createNotifier($messages);
        $translator = $this->createTranslator();
        $accessHelper = $this->createAccessHelper(true);
        $registry = new OperServCommandRegistry([]);

        $gline = Gline::create('*@host1234.com', 1, 'Test reason');
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->method('findByMask')->willReturn($gline);
        $glineRepo->expects(self::once())->method('remove');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('removeGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithRepo($glineRepo, 1000, $connectionHolder);
        $context = $this->createContext($sender, ['DEL', '*@host1234.com'], $notifier, $translator, $registry, $accessHelper);
        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertNotNull($auditData);
        self::assertSame('*@host1234.com', $auditData->target);
        self::assertNull($auditData->reason);
    }

    private function createCommand(): GlineCommand
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $rootRegistry = new RootUserRegistry('');
        $accessHelper = $this->createAccessHelper(false);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        return new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            $rootRegistry,
            $accessHelper,
            $connectionHolder,
            new NullLogger(),
            1000,
        );
    }

    private function createCommandWithRepo(
        GlineRepositoryInterface $glineRepo,
        int $maxGlines = 1000,
        ?ActiveConnectionHolderInterface $connectionHolder = null,
    ): GlineCommand {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $nickRepo = $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $rootRegistry = new RootUserRegistry('');
        $accessHelper = $this->createAccessHelper(false);
        $connHolder = $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class);

        return new GlineCommand(
            $glineRepo,
            $userLookup,
            $nickRepo,
            $ircopRepo,
            $rootRegistry,
            $accessHelper,
            $connHolder,
            new NullLogger(),
            $maxGlines,
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'TestUser', 'ident', 'host.com', 'clines', 'aBcDeF=', false, true, 'SID1', 'h', 'o', '');
    }

    private function createNotifier(array &$messages): OperServNotifierInterface
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });
        $notifier->method('getNick')->willReturn('OperServ');

        return $notifier;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
        OperServCommandRegistry $registry,
        IrcopAccessHelper $accessHelper,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'GLINE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $accessHelper,
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): \App\Application\ApplicationPort\ServiceNicknameRegistry
    {
        $provider = new class('operserv', 'OperServ') implements \App\Application\ApplicationPort\ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new \App\Application\ApplicationPort\ServiceNicknameRegistry([$provider]);
    }

    #[Test]
    public function glineMatchesUserReturnsFalseForMaskWithoutAt(): void
    {
        $cmd = $this->createCommand();
        $ref = new ReflectionClass($cmd);
        $method = $ref->getMethod('glineMatchesUser');

        $result = $method->invoke($cmd, 'nomask', 'user@host.com');
        self::assertFalse($result);
    }

    #[Test]
    public function glineMatchesUserReturnsFalseForUserMaskWithoutAt(): void
    {
        $cmd = $this->createCommand();
        $ref = new ReflectionClass($cmd);
        $method = $ref->getMethod('glineMatchesUser');

        $result = $method->invoke($cmd, '*@host.com', 'nomask');
        self::assertFalse($result);
    }
}
