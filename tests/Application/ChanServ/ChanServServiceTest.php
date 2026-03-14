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
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN'], 'chanserv', 'en')
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
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }
}
