<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\NickServService;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NickServService::class)]
final class NickServServiceTest extends TestCase
{
    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $authorizationContext = $this->createStub(AuthorizationContextInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $pendingRegistry = new \App\Application\NickServ\PendingVerificationRegistry();
        $recoveryRegistry = new \App\Application\NickServ\RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(
                private readonly stdClass $contextHolder,
            ) {
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

            public function execute(NickServContext $context): void
            {
                $this->contextHolder->context = $context;
            }
        };

        $registry = new NickServCommandRegistry([$handler]);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');
        $account->method('getTimezone')->willReturn('Europe/Madrid');
        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with($sender->nick)->willReturn($account);

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            defaultLanguage: 'en',
            defaultTimezone: 'UTC',
            logger: $logger,
        );

        $service->dispatch('FOO arg1', $sender);

        self::assertInstanceOf(NickServContext::class, $contextHolder->context);
        self::assertSame('FOO', $contextHolder->context->command);
        self::assertSame(['arg1'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $authorizationContext = $this->createStub(AuthorizationContextInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $pendingRegistry = new \App\Application\NickServ\PendingVerificationRegistry();
        $recoveryRegistry = new \App\Application\NickServ\RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $registry = new NickServCommandRegistry([]);

        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'unknown_command',
                ['command' => 'UNKNOWN'],
                'nickserv',
                'en',
            )
            ->willReturn('Unknown command');

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            defaultLanguage: 'en',
            defaultTimezone: 'UTC',
            logger: $logger,
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }
}
