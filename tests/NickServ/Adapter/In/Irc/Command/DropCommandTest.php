<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\DropCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Drop\DropNick;
use App\NickServ\Application\UseCase\Drop\DropNickHandlerInterface;
use App\NickServ\Application\UseCase\Drop\DropNickResult;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function implode;
use function is_scalar;
use function ksort;

use const DATE_ATOM;

#[CoversClass(DropCommand::class)]
final class DropCommandTest extends TestCase
{
    #[Test]
    public function exposesDropMetadata(): void
    {
        $command = new DropCommand(
            $this->createStub(DropNickHandlerInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->clock(),
            $this->createStub(AuthorizationCheckerInterface::class),
        );

        self::assertSame('DROP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('drop.syntax', $command->getSyntaxKey());
        self::assertSame('drop.help', $command->getHelpKey());
        self::assertSame('drop.example', $command->getExampleKey());
        self::assertSame(71, $command->getOrder());
        self::assertSame('drop.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::DROP, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(DropNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new DropCommand($handler, $this->createStub(LoggerInterface::class), $this->clock(), $this->createStub(AuthorizationCheckerInterface::class));
        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['Target']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsArgsAndChecksForcePermission(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->expects(self::once())->method('isGranted')
            ->with(NickServPermission::DROP_FORCE, self::isInstanceOf(NickServContext::class))
            ->willReturn(true);

        $handler = $this->createMock(DropNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (DropNick $dto): bool => 'TargetNick' === $dto->targetNick
                && 'OperNick' === $dto->operatorNick
                && '2026-09-06T12:00:00+00:00' === $dto->occurredAt->format(DATE_ATOM)
                && $dto->force
                && $dto->forceAllowed,
        ))->willReturn(DropNickResult::hardDropSuccess('TargetNick'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $command = new DropCommand($handler, $logger, $this->clock(), $authChecker);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'force']));

        self::assertTrue($outcome->success);
        self::assertSame(['drop.force_success [%nickname%: TargetNick]'], $messages);
        self::assertSame('TargetNick', $outcome->auditData?->target);
        self::assertTrue($outcome->auditData?->extra['force'] ?? false);
    }

    #[Test]
    public function mapsArgsWhenForceNotAllowedOrNotSpecified(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(DropNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (DropNick $dto): bool => 'TargetNick' === $dto->targetNick
                && 'OperNick' === $dto->operatorNick
                && !$dto->force
                && !$dto->forceAllowed,
        ))->willReturn(DropNickResult::softDropSuccess('TargetNick'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $command = new DropCommand($handler, $logger, $this->clock(), $this->createStub(AuthorizationCheckerInterface::class));
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertTrue($outcome->success);
        self::assertSame(['drop.success [%nickname%: TargetNick]'], $messages);
        self::assertSame('TargetNick', $outcome->auditData?->target);
    }

    /**
     * @param string[] $expectedMessages
     */
    #[Test]
    #[DataProvider('rejectionOutcomesProvider')]
    public function presentsRejections(DropNickResult $result, array $expectedMessages): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(DropNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($result);

        $command = new DropCommand($handler, $this->createStub(LoggerInterface::class), $this->clock(), $this->createStub(AuthorizationCheckerInterface::class));
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertFalse($outcome->success);
        self::assertSame($expectedMessages, $messages);
    }

    /**
     * @return array<string, array{0: DropNickResult, 1: string[]}>
     */
    public static function rejectionOutcomesProvider(): array
    {
        return [
            'cannot_drop_self' => [
                DropNickResult::cannotDropSelf(),
                ['drop.cannot_drop_self'],
            ],
            'not_registered' => [
                DropNickResult::notRegistered('TargetNick'),
                ['error.nick_not_registered [%nickname%: TargetNick]'],
            ],
            'pending_deletion' => [
                DropNickResult::pendingDeletion('TargetNick'),
                ['drop.pending_deletion [%nickname%: TargetNick]'],
            ],
            'force_permission_denied' => [
                DropNickResult::forcePermissionDenied(),
                ['error.permission_denied'],
            ],
            'suspended' => [
                DropNickResult::suspended('TargetNick'),
                ['drop.suspended [%nickname%: TargetNick]'],
            ],
            'forbidden' => [
                DropNickResult::forbidden('TargetNick'),
                ['drop.forbidden [%nickname%: TargetNick]'],
            ],
            'cannot_drop_root' => [
                DropNickResult::cannotDropRoot('TargetNick'),
                ['drop.cannot_drop_root [%nickname%: TargetNick]'],
            ],
            'cannot_drop_oper' => [
                DropNickResult::cannotDropOper('TargetNick'),
                ['drop.cannot_drop_oper [%nickname%: TargetNick]'],
            ],
            'cannot_drop_service' => [
                DropNickResult::cannotDropService('TargetNick'),
                ['drop.cannot_drop_service [%nickname%: TargetNick]'],
            ],
        ];
    }

    #[Test]
    public function dropSuccessAndForbiddenHaveDistinctRoles(): void
    {
        $handler = $this->createStub(DropNickHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(DropNickResult::softDropSuccess('Alice'), DropNickResult::forbidden('Alice'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip'), null, 'DROP', ['Alice'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
        $command = new DropCommand($handler, $this->createStub(LoggerInterface::class), $this->clock(), $this->createStub(AuthorizationCheckerInterface::class));
        $command->execute($context);
        $command->execute($context);
        self::assertStringStartsWith("\x0303✔\x03\x0F ", $messages[0]);
        self::assertStringContainsString("\x02Alice\x03\x0F", $messages[0]);
        self::assertStringStartsWith("\x0304✗\x03\x0F ", $messages[1]);
        self::assertStringContainsString("\x02Alice\x03\x0F", $messages[1]);
    }

    /**
     * @param string[]     $messages
     * @param list<string> $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            unset($params['%bot%'], $params['%nickserv%']);
            if ([] === $params) {
                return $id;
            }
            ksort($params);
            $formatted = [];
            foreach ($params as $key => $value) {
                $formatted[] = (string) $key . ': ' . (is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
            }

            return $id . ' [' . implode(', ', $formatted) . ']';
        });

        return new NickServContext(
            $sender,
            null,
            'DROP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-06 12:00:00 UTC'));

        return $clock;
    }
}
