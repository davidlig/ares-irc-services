<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\OperServ\Command\Handler\GlobalCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceNickReservationInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(GlobalCommand::class)]
final class GlobalCommandTest extends TestCase
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
        TranslatorInterface $translator,
    ): OperServContext {
        $registry = new OperServCommandRegistry([]);

        return new OperServContext(
            $sender,
            null,
            'GLOBAL',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createAccessHelper(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
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

        return new ServiceNicknameRegistry([$provider]);
    }

    private function createUidRegistry(?string $nickservUid = null): ServiceUidRegistry
    {
        $providers = [];

        if (null !== $nickservUid) {
            $providers['nickserv'] = new class($nickservUid) implements ServiceUidProviderInterface {
                public function __construct(private string $uid)
                {
                }

                public function getServiceKey(): string
                {
                    return 'nickserv';
                }

                public function getNickname(): string
                {
                    return 'NickServ';
                }

                public function getUid(): string
                {
                    return $this->uid;
                }
            };
        }

        return ServiceUidRegistry::fromIterable($providers);
    }

    private function createCommand(
        ?NetworkUserLookupPort $userLookup = null,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
        ?ServiceUidRegistry $uidRegistry = null,
        ?ActiveConnectionHolderInterface $connectionHolder = null,
        ?SendNoticePort $sendNoticePort = null,
    ): GlobalCommand {
        $uidGenerator = new PseudoClientUidGenerator($connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class));

        return new GlobalCommand(
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $uidRegistry ?? $this->createUidRegistry(),
            $uidGenerator,
            $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $sendNoticePort ?? $this->createStub(SendNoticePort::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function getNameReturnsGlobal(): void
    {
        self::assertSame('GLOBAL', $this->createCommand()->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsThree(): void
    {
        self::assertSame(3, $this->createCommand()->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        self::assertSame('global.syntax', $this->createCommand()->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        self::assertSame('global.help', $this->createCommand()->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        self::assertSame(50, $this->createCommand()->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        self::assertSame('global.short', $this->createCommand()->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand()->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        self::assertFalse($this->createCommand()->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsGlobalPermission(): void
    {
        self::assertSame(OperServPermission::GLOBAL, $this->createCommand()->getRequiredPermission());
    }

    #[Test]
    public function executeWithNoSenderReturnsEarly(): void
    {
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $context = $this->createContext(null, ['TestBot!bot@test.com', 'PRIVMSG', 'Hello'], $notifier, $translator);
        $command = $this->createCommand();

        $command->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithInvalidTypeRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'INVALID', 'Hello'], $notifier, $translator);
        $command = $this->createCommand();

        $command->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('global.type_invalid', $messages[0]);
    }

    #[Test]
    public function executeWithInvalidMaskRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $context = $this->createContext($sender, ['invalid_mask', 'PRIVMSG', 'Hello'], $notifier, $translator);
        $command = $this->createCommand();

        $command->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('global.mask_invalid', $messages[0]);
    }

    #[Test]
    public function executeWithConnectedNicknameRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $connectedUser = new SenderView('UID2', 'TestBot', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', '', '');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($connectedUser);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'PRIVMSG', 'Hello'], $notifier, $translator);
        $command = $this->createCommand(userLookup: $userLookup);

        $command->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('global.nick_connected', $messages[0]);
    }

    #[Test]
    public function executeWithRegisteredNicknameRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $registeredNick = $this->createStub(RegisteredNick::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($registeredNick);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'PRIVMSG', 'Hello'], $notifier, $translator);
        $command = $this->createCommand(userLookup: $userLookup, nickRepository: $nickRepository);

        $command->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('global.nick_registered', $messages[0]);
    }

    #[Test]
    public function executeWithNoProtocolModuleReturnsEarly(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $userLookup->method('listConnectedUids')->willReturn([]);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'PRIVMSG', 'Hello'], $notifier, $translator);
        $command = $this->createCommand(userLookup: $userLookup, nickRepository: $nickRepository, connectionHolder: $connectionHolder);

        $command->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeSendsMessageToAllUsers(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');

        $notifier = $this->createStub(OperServNotifierInterface::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $userLookup->method('listConnectedUids')->willReturn(['UID2', 'UID3', 'UID4']);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $sendMessages = [];
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::exactly(3))
            ->method('sendMessage')
            ->willReturnCallback(static function (string $fromUid, string $toUid, string $message, string $type) use (&$sendMessages): void {
                $sendMessages[] = ['from' => $fromUid, 'to' => $toUid, 'message' => $message, 'type' => $type];
            });

        $nickReservation = $this->createMock(ServiceNickReservationInterface::class);
        $nickReservation->expects(self::once())->method('reserveNickWithDuration');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introducePseudoClient');
        $serviceActions->expects(self::once())->method('quitPseudoClient');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($nickReservation);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('001');
        $connectionHolder->method('getConnection')->willReturn($connection);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'PRIVMSG', 'Hello World'], $notifier, $translator);
        $command = $this->createCommand(
            userLookup: $userLookup,
            nickRepository: $nickRepository,
            connectionHolder: $connectionHolder,
            sendNoticePort: $sendNoticePort,
        );

        $command->execute($context);

        self::assertCount(3, $sendMessages);
        self::assertSame('Hello World', $sendMessages[0]['message']);
        self::assertSame('PRIVMSG', $sendMessages[0]['type']);
    }

    #[Test]
    public function executeSendsToAllUsersIncludingSender(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');

        $notifier = $this->createStub(OperServNotifierInterface::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $userLookup->method('listConnectedUids')->willReturn(['UID1', 'UID2']);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $sendMessages = [];
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::exactly(2))
            ->method('sendMessage')
            ->willReturnCallback(static function (string $fromUid, string $toUid, string $message, string $type) use (&$sendMessages): void {
                $sendMessages[] = ['from' => $fromUid, 'to' => $toUid, 'message' => $message, 'type' => $type];
            });

        $nickReservation = $this->createMock(ServiceNickReservationInterface::class);
        $nickReservation->expects(self::once())->method('reserveNickWithDuration');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introducePseudoClient');
        $serviceActions->expects(self::once())->method('quitPseudoClient');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($nickReservation);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('001');
        $connectionHolder->method('getConnection')->willReturn($connection);

        $context = $this->createContext($sender, ['TestBot!bot@test.com', 'NOTICE', 'Hello'], $notifier, $translator);
        $command = $this->createCommand(
            userLookup: $userLookup,
            nickRepository: $nickRepository,
            connectionHolder: $connectionHolder,
            sendNoticePort: $sendNoticePort,
        );

        $command->execute($context);

        self::assertCount(2, $sendMessages);
        self::assertSame('UID1', $sendMessages[0]['to']);
        self::assertSame('UID2', $sendMessages[1]['to']);
        self::assertSame('NOTICE', $sendMessages[0]['type']);
        self::assertSame('NOTICE', $sendMessages[1]['type']);
    }

    #[Test]
    public function executeWithServiceNicknameUsesExistingServiceUid(): void
    {
        $sender = new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');

        $notifier = $this->createStub(OperServNotifierInterface::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('listConnectedUids')->willReturn(['UID1', 'UID2', 'UID3']);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);

        $nickservUid = '002NICKSV';  // NickServ UID
        $uidRegistry = $this->createUidRegistry($nickservUid);

        $sendMessages = [];
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::exactly(3))
            ->method('sendMessage')
            ->willReturnCallback(static function (string $fromUid, string $toUid, string $message, string $type) use (&$sendMessages): void {
                $sendMessages[] = ['from' => $fromUid, 'to' => $toUid, 'message' => $message, 'type' => $type];
            });

        // NO introducePseudoClient, NO quitPseudoClient for service nicks
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('introducePseudoClient');
        $serviceActions->expects(self::never())->method('quitPseudoClient');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $context = $this->createContext($sender, ['NickServ!services@host', 'PRIVMSG', 'Test message'], $notifier, $translator);
        $command = $this->createCommand(
            userLookup: $userLookup,
            nickRepository: $nickRepository,
            uidRegistry: $uidRegistry,
            connectionHolder: $connectionHolder,
            sendNoticePort: $sendNoticePort,
        );

        $command->execute($context);

        self::assertCount(3, $sendMessages);
        // Messages come FROM the service UID
        self::assertSame($nickservUid, $sendMessages[0]['from']);
        self::assertSame('Test message', $sendMessages[0]['message']);
        self::assertSame('PRIVMSG', $sendMessages[0]['type']);
    }
}
