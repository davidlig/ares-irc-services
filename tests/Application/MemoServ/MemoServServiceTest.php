<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ;

use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\MemoServService;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServService::class)]
final class MemoServServiceTest extends TestCase
{
    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $contextHolder = new \stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly \stdClass $holder) {}

            public function getName(): string { return 'FOO'; }
            public function getAliases(): array { return []; }
            public function getMinArgs(): int { return 1; }
            public function getSyntaxKey(): string { return 'dummy.syntax'; }
            public function getHelpKey(): string { return 'dummy.help'; }
            public function getOrder(): int { return 0; }
            public function getShortDescKey(): string { return 'dummy.short'; }
            public function getSubCommandHelp(): array { return []; }
            public function isOperOnly(): bool { return false; }
            public function getRequiredPermission(): ?string { return null; }
            public function execute(MemoServContext $context): void { $this->holder->context = $context; }
        };

        $registry = new MemoServCommandRegistry([$handler]);
        $service = new MemoServService(
            $registry,
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('FOO arg1', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
        self::assertSame('FOO', $contextHolder->context->command);
        self::assertSame(['arg1'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN'], 'memoserv', 'en')
            ->willReturn('Unknown command');
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = new MemoServService(
            new MemoServCommandRegistry([]),
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
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
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $service = new MemoServService(
            new MemoServCommandRegistry([]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }
}
