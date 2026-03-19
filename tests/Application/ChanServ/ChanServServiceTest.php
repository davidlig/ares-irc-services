<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ;

use App\Application\ChanServ\ChanServService;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServService::class)]
final class ChanServServiceTest extends TestCase
{
    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);
        $translator = $this->createStub(TranslatorInterface::class);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));
        $logger = $this->createStub(LoggerInterface::class);

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $registry = new ChanServCommandRegistry([$handler]);

        $service = new ChanServService(
            $registry,
            $channelRepository,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $channelLookup,
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
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

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'ChanServ'], 'chanserv', 'en')
            ->willReturn('Unknown command');
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = new ChanServService(
            new ChanServCommandRegistry([]),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
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

        $service = new ChanServService(
            new ChanServCommandRegistry([]),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesOperOnlyWhenHandlerIsOperOnly(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $operOnlyHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = [], string $domain = '', ?string $locale = null): string => 'error.oper_only' === $id ? 'Oper only' : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Oper only', 'NOTICE');

        $registry = new ChanServCommandRegistry([$operOnlyHandler]);
        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        $service->dispatch('OPCMD', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndNoAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $identifiedHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function getRequiredPermission(): ?string
            {
                return 'IDENTIFIED';
            }

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Not identified', 'NOTICE');

        $registry = new ChanServCommandRegistry([$identifiedHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $minArgsHandler = new class($contextHolder) implements ChanServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function execute(ChanServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . ($params['syntax'] ?? '') : $id
        );
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $registry = new ChanServCommandRegistry([$minArgsHandler]);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
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
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
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

            public function execute(ChanServContext $context): void
            {
                throw ChannelNotRegisteredException::forChannel('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
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

            public function execute(ChanServContext $context): void
            {
                throw new ChannelAlreadyRegisteredException('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
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

            public function execute(ChanServContext $context): void
            {
                throw new InsufficientAccessException('#test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $registry = new ChanServCommandRegistry([$throwingHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($this->createStub(\App\Application\Port\ChannelModeSupportInterface::class));

        $service = new ChanServService(
            $registry,
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepository,
            $this->createStub(ChanServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $modeSupportProvider,
            $this->createStub(NetworkUserLookupPort::class),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(InsufficientAccessException::class);

        $service->dispatch('FAIL', $sender);
    }
}
