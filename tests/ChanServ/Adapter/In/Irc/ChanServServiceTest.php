<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface as AuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface as AuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function is_string;

final class ChanServTestContextHolder
{
    public ?ChanServContext $context = null;
}

final class ChanServTestAuditRecorder implements CommandAuditRecorder
{
    /** @var list<CommandAuditRecord> */
    public array $records = [];

    public function record(CommandAuditRecord $record): void
    {
        $this->records[] = $record;
    }
}

#[CoversClass(ChanServService::class)]
final class ChanServServiceTest extends TestCase
{
    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
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
        $chanservProvider = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
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
        $memoservProvider = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
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
        $operservProvider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
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

        return new ServiceNicknameRegistry([
            $nickservProvider,
            $chanservProvider,
            $memoservProvider,
            $operservProvider,
        ]);
    }

    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messageTypeResolver = $this->createMessageTypeResolver($nickRepository);
        $translator = $this->createStub(TranslationInterface::class);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));
        $logger = $this->createStub(LoggerInterface::class);

        $contextHolder = new ChanServTestContextHolder();
        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'FOO';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'dummy.syntax';
            }

            public function getHelpKey(): string
            {
                return 'dummy.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'dummy.short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $channelLookup,
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('FOO arg1', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertSame('FOO', $contextHolder->context->command);
        self::assertSame(['arg1'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslationInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'ChanServ'], 'chanserv', 'en')
            ->willReturn('Unknown command');
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = $this->createChanServService(
            new ChanServCommandRegistry([]),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }

    #[Test]
    public function emptyCommandDoesNothing(): void
    {
        $sender = new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip');
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $service = $this->createChanServService(
            new ChanServCommandRegistry([]),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesPermissionDeniedWhenHandlerRequiresPermissionAndUserLacksIt(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $permissionHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'OPCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'CHANSERV_OP_TEST';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CHANSERV_OP_TEST', self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied' : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Permission denied', 'NOTICE');

        $registry = new ChanServCommandRegistry([$permissionHandler]);
        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function blocksNormalCommandOnPendingDeletionChannel(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SOMEOP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isPendingDeletion')->willReturn(true);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('drop.pending_deletion', self::anything(), 'chanserv', 'en')
            ->willReturn('Channel #test is pending deletion');

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, 'Channel #test is pending deletion', 'NOTICE');

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
        );

        $service->dispatch('SOMEOP #test', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndNoAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $identifiedHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'NEEDID';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn(null);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Not identified', 'NOTICE');

        $registry = new ChanServCommandRegistry([$identifiedHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $minArgsHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'TWOARGS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 2;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.twoargs';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . (is_string($params['syntax'] ?? null) ? $params['syntax'] : '') : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $registry = new ChanServCommandRegistry([$minArgsHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('TWOARGS onlyone', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function whenHandlerThrowsGenericThrowableLogsAndRethrows(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwingHandler = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'THROW';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                throw new RuntimeException('Handler failed for test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(
                'ChanServ dispatch error: Handler failed for test',
                self::callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] instanceof RuntimeException
                        && isset($context['sender']) && 'UID1' === $context['sender'])
            );

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler failed for test');

        $service->dispatch('THROW', $sender);
    }

    #[Test]
    public function rethrowsChannelNotRegisteredExceptionWithoutLogging(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwingHandler = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'FAIL';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                throw ChannelNotRegisteredException::forChannel('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(ChannelNotRegisteredException::class);

        $service->dispatch('FAIL', $sender);
    }

    #[Test]
    public function rethrowsChannelAlreadyRegisteredExceptionWithoutLogging(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwingHandler = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'FAIL';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                throw new ChannelAlreadyRegisteredException('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(ChannelAlreadyRegisteredException::class);

        $service->dispatch('FAIL', $sender);
    }

    #[Test]
    public function rethrowsInsufficientAccessExceptionWithoutLogging(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwingHandler = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'FAIL';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                throw new InsufficientAccessException('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(InsufficientAccessException::class);

        $service->dispatch('FAIL', $sender);
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithSuccessfulOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $auditableHandler = new class($contextHolder) implements ChanServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'AUDITCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'CHANSPORT_FOUNDER';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): CommandOutcome
            {
                $auditData = new IrcopAuditData(
                    target: '#test',
                    targetHost: 'user@host',
                    targetIp: '127.0.0.1',
                    reason: 'test reason',
                    extra: ['key' => 'value'],
                );
                $this->holder->context = $context;

                return CommandOutcome::success($auditData);
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => $auditableHandler === $event->command
                && 'chanserv' === $event->serviceName
                && 'Nick' === $event->operatorNick
                && 'AUDITCMD' === $event->commandName
                && 'CHANSPORT_FOUNDER' === $event->permission
                && true === $event->outcome?->success
                && '#test' === $event->outcome->auditData?->target));

        $registry = new ChanServCommandRegistry([$auditableHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('AUDITCMD', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesCommandExecutedEventForNonAuditableHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $nonAuditableHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'NONAUDIT';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'CHANSERV_OP';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => $nonAuditableHandler === $event->command && null === $event->outcome));

        $registry = new ChanServCommandRegistry([$nonAuditableHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('NONAUDIT', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithRejectedOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $auditableHandler = new class($contextHolder) implements ChanServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'FAILCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'CHANSERV_OP';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): CommandOutcome
            {
                $this->holder->context = $context;

                return CommandOutcome::rejected();
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => false === $event->outcome?->success));

        $registry = new ChanServCommandRegistry([$auditableHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('FAILCMD', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    private function createPreferences(
        string $defaultLanguage = 'en',
        bool $prefersPrivate = false,
    ): ChanServUserPresentationPreferences {
        $resolver = $this->createStub(ChanServUserPresentationPreferences::class);
        $resolver->method('languageFor')->willReturnCallback(
            static fn (string $uid, string $nickname, ?string $accountLanguage): string => $accountLanguage ?? $defaultLanguage,
        );
        $resolver->method('defaultLanguage')->willReturn($defaultLanguage);
        $resolver->method('prefersPrivateMessages')->willReturn($prefersPrivate);

        return $resolver;
    }

    private function createMessageTypeResolver(mixed $ignored = null, bool $prefersPrivate = false): ChanServUserPresentationPreferences
    {
        return $this->createPreferences(prefersPrivate: $prefersPrivate);
    }

    private function createChanServService(
        ChanServCommandRegistry $registry,
        RegisteredChannelRepositoryInterface $channelRepository,
        ChanUserAccountPort $nickRepository,
        ChanServNotifierInterface $notifier,
        ChanServUserPresentationPreferences $messageTypeResolver,
        TranslationInterface $translator,
        ChannelLookupPort $channelLookup,
        ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        NetworkUserLookupPort $userLookup,
        ServiceNicknameRegistry $serviceNicks,
        string $defaultLanguage = 'en',
        string $defaultTimezone = 'UTC',
        ?LoggerInterface $logger = null,
        ?AuthorizationContextInterface $authorizationContext = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?EventBusInterface $eventDispatcher = null,
        ?CommandAuditRecorder $commandAudit = null,
    ): ChanServService {
        return new ChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $messageTypeResolver,
            $notifier,
            $translator,
            $channelLookup,
            $modeSupportProvider,
            $userLookup,
            $serviceNicks,
            $authorizationContext ?? $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker ?? $this->createStub(AuthorizationCheckerInterface::class),
            $eventDispatcher ?? $this->createStub(EventBusInterface::class),
            $commandAudit ?? $this->createStub(CommandAuditRecorder::class),
            $defaultLanguage,
            $defaultTimezone,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    #[Test]
    public function blocksCommandOnSuspendedChannel(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SOMEOP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return false;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isCurrentlySuspended')->willReturn(true);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('suspend.channel_suspended', self::anything(), 'chanserv', 'en')
            ->willReturn('Channel #test is suspended');

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, 'Channel #test is suspended', 'NOTICE');

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
        );

        $service->dispatch('SOMEOP #test', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function allowsCommandOnSuspendedChannelWhenAllowed(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'INFO';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('INFO #suspended', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    #[Test]
    public function doesNotBlockCommandWhenChannelFoundButNotCurrentlySuspended(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SOMEOP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return false;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isCurrentlySuspended')->willReturn(false);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('SOMEOP #test', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    #[Test]
    public function doesNotBlockCommandWhenChannelNotFoundForSuspendedCheck(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SOMEOP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return false;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn(null);

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(ChanServNotifierInterface::class),
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('SOMEOP #nonexistent', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
    }

    #[Test]
    public function repliesForbiddenWhenChannelIsForbiddenAndHandlerDoesNotAllowForbidden(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'ACCESS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return false;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('forbid.channel_forbidden', self::anything(), 'chanserv', 'en')
            ->willReturn('Channel #test is forbidden');

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, 'Channel #test is forbidden', 'NOTICE');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('chanserv.level_founder', self::anything())
            ->willReturn(false);

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('ACCESS #test', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function blocksCommandOnForbiddenChannelEvenForLevelFounder(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'ACCESS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return false;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(true);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('forbid.channel_forbidden', self::anything(), 'chanserv', 'en')
            ->willReturn('Channel #test is forbidden');

        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, 'Channel #test is forbidden', 'NOTICE');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('chanserv.level_founder', self::anything())
            ->willReturn(true);

        $registry = new ChanServCommandRegistry([$handler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $this->createStub(ChanUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver($this->createStub(ChanUserAccountPort::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('ACCESS #test', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function dispatchesLevelFounderAuditEventWhenIrcopIsNotRealFounder(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', base64_encode(inet_pton('127.0.0.1') ?: ''), true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return true;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isCurrentlySuspended')->willReturn(false);
        $channel->method('isFounder')->willReturn(false);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(4))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission || 'chanserv.level_founder' === $permission);

        $events = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $commandAudit = new ChanServTestAuditRecorder();

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
            commandAudit: $commandAudit,
        );

        $service->dispatch('SET #test DESC desc', $sender);
        $service->dispatch('SET #test PASSWORD never-audit-this', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
        self::assertInstanceOf(CommandExecutedEvent::class, $events[0]);
        $record = $commandAudit->records[0];
        self::assertSame(CommandAuditCategory::ResourceOverride, $record->category);
        self::assertSame('chanserv', $record->service);
        self::assertSame('OperNick', $record->actor);
        self::assertSame('SET', $record->operation);
        self::assertSame('chanserv.level_founder', $record->permission);
        self::assertSame('#test', $record->target);
        self::assertSame('ident@host', $record->targetHost);
        self::assertSame('127.0.0.1', $record->targetIp);
        self::assertSame(['founder_action' => true, 'option' => 'DESC', 'value' => 'desc'], $record->metadata);
        self::assertSame(
            ['founder_action' => true, 'option' => 'PASSWORD', 'value' => null],
            $commandAudit->records[1]->metadata,
        );
    }

    #[Test]
    public function doesNotDispatchLevelFounderAuditEventWhenIrcopIsRealFounder(): void
    {
        $sender = new SenderView('UID1', 'FounderNick', 'ident', 'host', 'cloak', 'AQ', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return true;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isCurrentlySuspended')->willReturn(false);
        $channel->method('isFounder')->willReturn(true);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission || 'chanserv.level_founder' === $permission);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CommandExecutedEvent::class));

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('SET #test DESC desc', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
    }

    #[Test]
    public function doesNotDispatchLevelFounderAuditEventWhenNotLevelFounder(): void
    {
        $sender = new SenderView('UID1', 'RegularUser', 'ident', 'host', 'cloak', 'AQ', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return true;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(99, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isCurrentlySuspended')->willReturn(false);
        $channel->method('isFounder')->willReturn(false);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CommandExecutedEvent::class));

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('SET #test DESC desc', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertFalse($contextHolder->context->isLevelFounder);
    }

    #[Test]
    public function doesNotDispatchLevelFounderAuditEventWhenCommandDoesNotUseLevelFounder(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', base64_encode(inet_pton('127.0.0.1') ?: ''), true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'DELACCESS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission || 'chanserv.level_founder' === $permission);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CommandExecutedEvent::class));

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('DELACCESS #test 5', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
    }

    #[Test]
    public function doesNotDispatchLevelFounderAuditEventForPublicCommand(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', base64_encode(inet_pton('127.0.0.1') ?: ''), true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'INFO';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('chanserv.level_founder', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CommandExecutedEvent::class));

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('INFO #test', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
    }

    #[Test]
    public function dispatchesLevelFounderAuditEventWithWildcardIpReturnsAsterisk(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', '*', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return true;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isCurrentlySuspended')->willReturn(false);
        $channel->method('isFounder')->willReturn(false);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission || 'chanserv.level_founder' === $permission);

        $events = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $commandAudit = new ChanServTestAuditRecorder();

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
            commandAudit: $commandAudit,
        );

        $service->dispatch('SET #test DESC desc', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
        self::assertInstanceOf(CommandExecutedEvent::class, $events[0]);
        $record = $commandAudit->records[0];
        self::assertSame(CommandAuditCategory::ResourceOverride, $record->category);
        self::assertSame('chanserv', $record->service);
        self::assertSame('OperNick', $record->actor);
        self::assertSame('SET', $record->operation);
        self::assertSame('chanserv.level_founder', $record->permission);
        self::assertSame('#test', $record->target);
        self::assertSame('ident@host', $record->targetHost);
        self::assertSame('*', $record->targetIp);
    }

    #[Test]
    public function dispatchesLevelFounderAuditEventWithInvalidBase64IpReturnsOriginalString(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', '!!invalid-base64!!', true, false, '001', 'cloak');
        $contextHolder = new ChanServTestContextHolder();

        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly ChanServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return false;
            }

            public function usesLevelFounder(): bool
            {
                return true;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $account = new ChanAccountView(42, $sender->nick, 'en', 'UTC');

        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($account);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isCurrentlySuspended')->willReturn(false);
        $channel->method('isFounder')->willReturn(false);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByChannelName')->willReturn($channel);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::exactly(2))
            ->method('isGranted')
            ->willReturnCallback(static fn (string $permission): bool => 'IDENTIFIED' === $permission || 'chanserv.level_founder' === $permission);

        $events = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $commandAudit = new ChanServTestAuditRecorder();

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        $registry = new ChanServCommandRegistry([$handler]);

        $service = $this->createChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $this->createMessageTypeResolver($nickRepository),
            $this->createStub(TranslationInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
            commandAudit: $commandAudit,
        );

        $service->dispatch('SET #test DESC desc', $sender);

        self::assertInstanceOf(ChanServContext::class, $contextHolder->context);
        self::assertTrue($contextHolder->context->isLevelFounder);
        self::assertInstanceOf(CommandExecutedEvent::class, $events[0]);
        $record = $commandAudit->records[0];
        self::assertSame(CommandAuditCategory::ResourceOverride, $record->category);
        self::assertSame('chanserv', $record->service);
        self::assertSame('OperNick', $record->actor);
        self::assertSame('SET', $record->operation);
        self::assertSame('chanserv.level_founder', $record->permission);
        self::assertSame('#test', $record->target);
        self::assertSame('ident@host', $record->targetHost);
        self::assertSame('!!invalid-base64!!', $record->targetIp);
    }
}
