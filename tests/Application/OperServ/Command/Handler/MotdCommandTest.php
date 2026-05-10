<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\Handler\MotdCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\SenderView;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MotdCommand::class)]
final class MotdCommandTest extends TestCase
{
    private function createAccessHelper(): IrcopAccessHelper
    {
        return new IrcopAccessHelper(
            new RootUserRegistry(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        return new ServiceNicknameRegistry([]);
    }

    private function createContext(array $args, ?SenderView $sender = null, ?TranslatorInterface $translator = null, ?OperServNotifierInterface $notifier = null): OperServContext
    {
        $sender ??= new SenderView(
            uid: '001ABC',
            nick: 'TestOper',
            ident: 'oper',
            hostname: 'oper.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'b3Blcg==',
            isIdentified: false,
            isOper: true,
            serverSid: '001',
        );

        $translator ??= $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $notifier ??= $this->createStub(OperServNotifierInterface::class);

        return new OperServContext(
            sender: $sender,
            senderAccount: null,
            command: 'MOTD',
            args: $args,
            notifier: $notifier,
            translator: $translator,
            language: 'en',
            timezone: 'UTC',
            messageType: 'NOTICE',
            registry: new OperServCommandRegistry([]),
            accessHelper: $this->createAccessHelper(),
            serviceNicks: $this->createServiceNicks(),
        );
    }

    #[Test]
    public function getNameReturnsMotd(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame('MOTD', $command->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame([], $command->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame(1, $command->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame('motd.syntax', $command->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame('motd.help', $command->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame(39, $command->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame('motd.short', $command->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsAddDelList(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $subcommands = $command->getSubCommandHelp();

        self::assertCount(4, $subcommands);
        self::assertSame('ADD', $subcommands[0]['name']);
        self::assertSame('DEL', $subcommands[1]['name']);
        self::assertSame('LIST', $subcommands[2]['name']);
        self::assertSame('CLEAN', $subcommands[3]['name']);
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertTrue($command->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsMotdPermission(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertSame(OperServPermission::MOTD, $command->getRequiredPermission());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));

        self::assertNull($command->getAuditData($this->createContext([])));
    }

    #[Test]
    public function executeWithUnknownSubcommandRepliesError(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.unknown_sub', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['UNKNOWN'], notifier: $notifier));
    }

    #[Test]
    public function doAddCreatesMotdEntry(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => 'Hello World!' === $m->getText()
                && 'NickServ' === $m->getBotNickname()
                && 'PRIVMSG' === $m->getMessageType()
                && null !== $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '7d', 'Hello', 'World!']));

        $auditData = $command->getAuditData($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '7d', 'Hello', 'World!']));
        self::assertNotNull($auditData);
        self::assertSame('NickServ', $auditData->target);
    }

    #[Test]
    public function doAddWithZeroExpiryIsPermament(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => 'Test msg' === $m->getText()
                && null === $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'ChanServ', 'NOTICE', '0', 'Test', 'msg']));
    }

    #[Test]
    public function doAddWithInvalidTypeRepliesError(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.add.invalid_type', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['ADD', 'NickServ', 'BROADCAST', '7d', 'Hello'], notifier: $notifier));
    }

    #[Test]
    public function doAddWithInvalidExpiryRepliesError(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.add.invalid_expiry', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', 'bad', 'Hello'], notifier: $notifier));
    }

    #[Test]
    public function doAddWithNoMessageTextRepliesSyntaxHint(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.add.syntax_hint', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '7d', ''], notifier: $notifier));
    }

    #[Test]
    public function doAddWithTooFewArgsRepliesSyntaxHint(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.add.syntax_hint', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['ADD'], notifier: $notifier));
    }

    #[Test]
    public function doDelRemovesMotdEntry(): void
    {
        $motd = Motd::create('Hello', 'NickServ', 'PRIVMSG');

        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->method('findById')->willReturnCallback(
            static fn (int $id): ?Motd => 1 === $id ? $motd : null,
        );
        $motdRepository
            ->expects(self::once())
            ->method('remove')
            ->with($motd);

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['DEL', '1']));

        $auditData = $command->getAuditData($this->createContext(['DEL', '1']));
        self::assertNotNull($auditData);
        self::assertSame('NickServ', $auditData->target);
    }

    #[Test]
    public function doDelNotifiesDebugChannelBeforeRemovingMotdEntry(): void
    {
        $motd = Motd::create('Hello', 'NickServ', 'PRIVMSG');
        $motd->recordShown();

        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->method('findById')->willReturn($motd);
        $motdRepository->expects(self::once())->method('remove')->with($motd);

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('notify')
            ->with(self::stringContains('motd.debug.finalized'));

        $command = new MotdCommand($motdRepository, $debugNotifier);
        $command->execute($this->createContext(['DEL', '1']));
    }

    #[Test]
    public function doDelWithNonNumericIdRepliesNotFound(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.del.not_found', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['DEL', 'abc'], notifier: $notifier));
    }

    #[Test]
    public function doDelWithNonexistentIdRepliesNotFound(): void
    {
        $motdRepository = $this->createStub(MotdRepositoryInterface::class);
        $motdRepository->method('findById')->willReturn(null);

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.del.not_found', 'NOTICE');

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['DEL', '99'], notifier: $notifier));
    }

    #[Test]
    public function doDelWithTooFewArgsRepliesSyntaxHint(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.del.syntax_hint', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['DEL'], notifier: $notifier));
    }

    #[Test]
    public function doListWithEntries(): void
    {
        $motd1 = Motd::create('First', 'NickServ', 'PRIVMSG');
        $motd2 = Motd::create('Second', 'ChanServ', 'NOTICE');

        $motdRepository = $this->createStub(MotdRepositoryInterface::class);
        $motdRepository->method('findAll')->willReturn([$motd1, $motd2]);

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::exactly(3))
            ->method('sendMessage');

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['LIST'], notifier: $notifier));
    }

    #[Test]
    public function doListWithExpiredEntry(): void
    {
        $motd = Motd::create('Expired', 'NickServ', 'PRIVMSG', null, new DateTimeImmutable('-1 day'));
        $motdRepository = $this->createStub(MotdRepositoryInterface::class);
        $motdRepository->method('findAll')->willReturn([$motd]);

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::exactly(2))->method('sendMessage');

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['LIST'], notifier: $notifier));
    }

    #[Test]
    public function doListEmptyRepliesNoEntries(): void
    {
        $motdRepository = $this->createStub(MotdRepositoryInterface::class);
        $motdRepository->method('findAll')->willReturn([]);

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.list.empty', 'NOTICE');

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['LIST'], notifier: $notifier));
    }

    #[Test]
    public function parseDurationWithSeconds(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => 'Hello' === $m->getText()
                && null !== $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '30s', 'Hello']));
    }

    #[Test]
    public function parseDurationWithMinutes(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => null !== $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '5m', 'Hello']));
    }

    #[Test]
    public function parseDurationWithHours(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => null !== $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '12h', 'Hello']));
    }

    #[Test]
    public function parseDurationWithDays(): void
    {
        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (Motd $m): bool => null !== $m->getExpiresAt()));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '7d', 'Hello']));
    }

    #[Test]
    public function parseDurationWithInvalidUnitReturnsError(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.add.invalid_expiry', 'NOTICE');

        $command = new MotdCommand($this->createStub(MotdRepositoryInterface::class));
        $command->execute($this->createContext(['ADD', 'NickServ', 'PRIVMSG', '5x', 'Hello'], notifier: $notifier));
    }

    #[Test]
    public function doCleanRemovesExpiredEntries(): void
    {
        $expired1 = Motd::create('Expired 1', 'Bot1', 'PRIVMSG', null, new DateTimeImmutable('-2 days'));
        $expired2 = Motd::create('Expired 2', 'Bot2', 'NOTICE', null, new DateTimeImmutable('-1 hour'));

        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->method('findExpired')->willReturn([$expired1, $expired2]);
        $motdRepository
            ->expects(self::exactly(2))
            ->method('remove')
            ->with(self::logicalOr($expired1, $expired2));

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['CLEAN']));

        $auditData = $command->getAuditData($this->createContext(['CLEAN']));
        self::assertNotNull($auditData);
    }

    #[Test]
    public function doCleanNotifiesDebugChannelForEveryExpiredMotd(): void
    {
        $expired1 = Motd::create('Expired 1', 'Bot1', 'PRIVMSG', null, new DateTimeImmutable('-2 days'));
        $expired2 = Motd::create('Expired 2', 'Bot2', 'NOTICE', null, new DateTimeImmutable('-1 hour'));

        $motdRepository = $this->createMock(MotdRepositoryInterface::class);
        $motdRepository->method('findExpired')->willReturn([$expired1, $expired2]);
        $motdRepository->expects(self::exactly(2))->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('notify')
            ->with(self::stringContains('motd.debug.finalized'));

        $command = new MotdCommand($motdRepository, $debugNotifier);
        $command->execute($this->createContext(['CLEAN']));
    }

    #[Test]
    public function doCleanWithNoExpiredEntries(): void
    {
        $motdRepository = $this->createStub(MotdRepositoryInterface::class);
        $motdRepository->method('findExpired')->willReturn([]);

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'motd.clean.none', 'NOTICE');

        $command = new MotdCommand($motdRepository);
        $command->execute($this->createContext(['CLEAN'], notifier: $notifier));
    }
}
