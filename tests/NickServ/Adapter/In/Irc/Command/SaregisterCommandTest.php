<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Saregister\SaregisterNick;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(SaregisterCommand::class)]
final class SaregisterCommandTest extends TestCase
{
    #[Test]
    public function metadata(): void
    {
        $command = new SaregisterCommand($this->createStub(SaregisterNickHandlerInterface::class));
        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame('saregister.example', $command->getExampleKey());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame(64, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame([], $command->getHelpParams());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::SAREGISTER, $command->getRequiredPermission());
    }

    #[Test]
    public function rejectsExecutionWithoutSender(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new SaregisterCommand($handler);
        $messages = [];
        $context = $this->createContext(null, $messages, ['Alice', 'pass123']);

        $outcome = $command->execute($context);
        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function rejectsExecutionWithInsufficientArguments(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new SaregisterCommand($handler);
        $messages = [];
        $sender = new SenderView('u1', 'OperNick', 'ident', 'host', '', '', true, true);
        $context = $this->createContext($sender, $messages, ['Alice']);

        $outcome = $command->execute($context);
        self::assertFalse($outcome->success);
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function rejectsExecutionWithTooManyArguments(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new SaregisterCommand($handler);
        $messages = [];
        $sender = new SenderView('u1', 'OperNick', 'ident', 'host', '', '', true, true);
        $context = $this->createContext($sender, $messages, ['Alice', 'pass123', 'alice@example.com', 'extra']);

        $outcome = $command->execute($context);
        self::assertFalse($outcome->success);
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function handlesTokenModeSuccess(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(function (SaregisterNick $cmd): bool {
            return 'Alice' === $cmd->nickname
                && 'secretPass' === $cmd->password
                && null === $cmd->email
                && 'OperNick' === $cmd->actorNickname
                && 'en' === $cmd->language;
        }))->willReturn(SaregisterNickResult::registeredToken('Alice', 'rescue-token-123'));

        $command = new SaregisterCommand($handler);
        $messages = [];
        $sender = new SenderView('u1', 'OperNick', 'ident', 'host', '', '', true, true);
        $context = $this->createContext($sender, $messages, ['Alice', 'secretPass']);

        $outcome = $command->execute($context);
        self::assertTrue($outcome->success);
        self::assertSame('Alice', $outcome->auditData?->target);
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function handlesEmailModeSuccess(): void
    {
        $handler = $this->createMock(SaregisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(function (SaregisterNick $cmd): bool {
            return 'Bob' === $cmd->nickname
                && 'secretPass' === $cmd->password
                && 'bob@example.com' === $cmd->email
                && 'OperNick' === $cmd->actorNickname;
        }))->willReturn(SaregisterNickResult::registeredEmail('Bob', 'bob@example.com'));

        $command = new SaregisterCommand($handler);
        $messages = [];
        $sender = new SenderView('u1', 'OperNick', 'ident', 'host', '', '', true, true);
        $context = $this->createContext($sender, $messages, ['Bob', 'secretPass', 'bob@example.com']);

        $outcome = $command->execute($context);
        self::assertTrue($outcome->success);
        self::assertSame('Bob', $outcome->auditData?->target);
        self::assertNotEmpty($messages);
    }

    #[Test]
    public function handlesAllErrorOutcomes(): void
    {
        $cases = [
            SaregisterNickResult::invalidNickname('bad#nick'),
            SaregisterNickResult::guestPrefixForbidden('Guest-'),
            SaregisterNickResult::invalidEmail(),
            SaregisterNickResult::blockedEmailDomain(),
            SaregisterNickResult::emailAlreadyUsed('used@example.com'),
            SaregisterNickResult::alreadyPending('Target'),
            SaregisterNickResult::forbidden('Target'),
            SaregisterNickResult::pendingDeletion('Target'),
            SaregisterNickResult::alreadyRegistered('Target'),
            SaregisterNickResult::mailDeliveryFailed('Target'),
        ];

        foreach ($cases as $result) {
            $handler = $this->createMock(SaregisterNickHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn($result);

            $command = new SaregisterCommand($handler);
            $messages = [];
            $sender = new SenderView('u1', 'OperNick', 'ident', 'host', '', '', true, true);
            $context = $this->createContext($sender, $messages, ['Target', 'secretPass']);

            $outcome = $command->execute($context);
            self::assertFalse($outcome->success);
            self::assertNotEmpty($messages);
        }
    }

    /**
     * @param list<string> $messages
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
            'SAREGISTER',
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
}
