<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\StatusCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Status\StatusNick;
use App\NickServ\Application\UseCase\Status\StatusNickHandlerInterface;
use App\NickServ\Application\UseCase\Status\StatusNickResult;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_repeat;

#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function exposesStatusMetadata(): void
    {
        $command = new StatusCommand(
            $this->createStub(StatusNickHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertSame('STATUS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('status.syntax', $command->getSyntaxKey());
        self::assertSame('status.help', $command->getHelpKey());
        self::assertSame('status.example', $command->getExampleKey());
        self::assertSame(6, $command->getOrder());
        self::assertSame('status.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsTargetNickAndOnlinePresence(): void
    {
        $onlineUser = new SenderView('UID1', 'target', 'ident', 'host', 'cloak', 'ip', isIdentified: true);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('target')->willReturn($onlineUser);

        $handler = $this->createMock(StatusNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (StatusNick $input): bool => 'target' === $input->nickname
                && true === $input->isOnline
                && true === $input->isIdentified))
            ->willReturn(StatusNickResult::registeredIdentified('target'));

        $command = new StatusCommand($handler, $userLookup);
        $messages = [];
        $command->execute($this->createContext($messages, ['target']));

        self::assertSame(['status.header', 'status.identified', 'reply.separator'], $messages);
    }

    #[Test]
    public function mapsTargetNickWhenOffline(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('ghost')->willReturn(null);

        $handler = $this->createMock(StatusNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (StatusNick $input): bool => 'ghost' === $input->nickname
                && false === $input->isOnline
                && false === $input->isIdentified))
            ->willReturn(StatusNickResult::unregisteredOffline('ghost'));

        $command = new StatusCommand($handler, $userLookup);
        $messages = [];
        $command->execute($this->createContext($messages, ['ghost']));

        self::assertSame(['status.header', 'status.not_registered_offline', 'reply.separator'], $messages);
    }

    #[Test]
    public function presentsPendingWithAndWithoutExpiresAt(): void
    {
        $handler = $this->createStub(StatusNickHandlerInterface::class);
        $command = new StatusCommand($handler, $this->createStub(NetworkUserLookupPort::class));

        // With expiresAt
        $handler->method('handle')->willReturn(StatusNickResult::pending('alice', $this->now, 5));
        $messages = [];
        $command->execute($this->createContext($messages, ['alice']));
        self::assertSame([
            'status.header',
            'status.pending',
            'status.pending_expires',
            'status.pending_expires_at',
            'reply.separator',
        ], $messages);

        // Without expiresAt
        $handler = $this->createStub(StatusNickHandlerInterface::class);
        $handler->method('handle')->willReturn(StatusNickResult::pending('alice', null, 0));
        $command = new StatusCommand($handler, $this->createStub(NetworkUserLookupPort::class));
        $messages = [];
        $command->execute($this->createContext($messages, ['alice']));
        self::assertSame([
            'status.header',
            'status.pending',
            'reply.separator',
        ], $messages);
    }

    #[Test]
    public function presentsSimpleOutcomes(): void
    {
        $cases = [
            [StatusNickResult::unregisteredOnline('nick'), ['status.header', 'status.unregistered', 'reply.separator']],
            [StatusNickResult::registeredNotConnected('nick'), ['status.header', 'status.not_connected', 'reply.separator']],
            [StatusNickResult::registeredNotIdentified('nick'), ['status.header', 'status.not_identified', 'reply.separator']],
        ];

        foreach ($cases as [$result, $expectedMessages]) {
            $handler = $this->createStub(StatusNickHandlerInterface::class);
            $handler->method('handle')->willReturn($result);
            $command = new StatusCommand($handler, $this->createStub(NetworkUserLookupPort::class));
            $messages = [];
            $command->execute($this->createContext($messages, ['nick']));
            self::assertSame($expectedMessages, $messages);
        }
    }

    #[Test]
    public function presentsSuspendedTemporaryAndPermanent(): void
    {
        $command = new StatusCommand(
            $this->createStub(StatusNickHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        // Temporary with reason
        $handler1 = $this->createStub(StatusNickHandlerInterface::class);
        $handler1->method('handle')->willReturn(StatusNickResult::suspended('bad', 'Rule violation', $this->now));
        $messages = [];
        new StatusCommand($handler1, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['bad']));
        self::assertSame([
            'status.header',
            'status.suspended',
            'status.suspended_reason',
            'status.suspended_until',
            'reply.separator',
        ], $messages);

        // Permanent without reason
        $handler2 = $this->createStub(StatusNickHandlerInterface::class);
        $handler2->method('handle')->willReturn(StatusNickResult::suspended('bad', null, null));
        $messages = [];
        new StatusCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['bad']));
        self::assertSame([
            'status.header',
            'status.suspended',
            'status.suspended_permanent',
            'reply.separator',
        ], $messages);
    }

    #[Test]
    public function presentsForbiddenWithAndWithoutReason(): void
    {
        // With reason
        $handler1 = $this->createStub(StatusNickHandlerInterface::class);
        $handler1->method('handle')->willReturn(StatusNickResult::forbidden('root', 'System account'));
        $messages = [];
        new StatusCommand($handler1, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['root']));
        self::assertSame([
            'status.header',
            'status.forbidden',
            'status.forbidden_reason',
            'reply.separator',
        ], $messages);

        // Without reason
        $handler2 = $this->createStub(StatusNickHandlerInterface::class);
        $handler2->method('handle')->willReturn(StatusNickResult::forbidden('root', null));
        $messages = [];
        new StatusCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['root']));
        self::assertSame([
            'status.header',
            'status.forbidden',
            'reply.separator',
        ], $messages);
    }

    #[Test]
    public function presentsPendingDeletionWithAndWithoutDate(): void
    {
        // With date
        $handler1 = $this->createStub(StatusNickHandlerInterface::class);
        $handler1->method('handle')->willReturn(StatusNickResult::pendingDeletion('retiring', $this->now));
        $messages = [];
        new StatusCommand($handler1, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['retiring']));
        self::assertSame([
            'status.header',
            'status.pending_deletion',
            'status.pending_deletion_at',
            'reply.separator',
        ], $messages);

        // Without date
        $handler2 = $this->createStub(StatusNickHandlerInterface::class);
        $handler2->method('handle')->willReturn(StatusNickResult::pendingDeletion('retiring', null));
        $messages = [];
        new StatusCommand($handler2, $this->createStub(NetworkUserLookupPort::class))->execute($this->createContext($messages, ['retiring']));
        self::assertSame([
            'status.header',
            'status.pending_deletion',
            'reply.separator',
        ], $messages);
    }

    #[Test]
    public function statusRendersLocalizedRowsAndSemanticFragmentsWithoutOutcomeMarkers(): void
    {
        $handler = $this->createStub(StatusNickHandlerInterface::class);
        $handler->method('handle')->willReturn(StatusNickResult::pending('Alice'));
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['status.header' => 'Status of %nickname%', 'status.pending' => '  Status: %state%', 'status.value_pending' => 'Pending'], 'en', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'Sender', 'ident', 'host', 'cloak', 'ip'), null, 'STATUS', ['Alice'], $notifier, $translator, 'en', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$this->nicknameProvider()]), $renderer);
        new StatusCommand($handler, $this->createStub(NetworkUserLookupPort::class))->execute($context);
        self::assertSame(["\x02Status of \x03\x0F\x0307Alice\x03\x0F", "  Status: \x0307Pending\x03\x0F", "\x0314" . str_repeat('─', 29) . "\x03\x0F"], $messages);
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 17:00:00 UTC');
    }

    /**
     * @param list<mixed>  $messages
     * @param list<string> $args
     */
    private function createContext(
        array &$messages,
        array $args,
    ): NickServContext {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages): string {
            $messages[] = $key;

            return $key;
        });

        $sender = new SenderView('UID1', 'Sender', 'ident', 'host', 'cloak', 'ip');

        return new NickServContext(
            $sender,
            null,
            'STATUS',
            $args,
            $this->createStub(NickServNotifierInterface::class),
            $translator,
            'es',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
    }
}
