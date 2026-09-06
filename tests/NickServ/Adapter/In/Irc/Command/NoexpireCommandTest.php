<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\NoexpireCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNick;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickHandlerInterface;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function is_scalar;

#[CoversClass(NoexpireCommand::class)]
final class NoexpireCommandTest extends TestCase
{
    #[Test]
    public function exposesNoexpireMetadata(): void
    {
        $command = new NoexpireCommand($this->createStub(SetNoexpireNickHandlerInterface::class));

        self::assertSame('NOEXPIRE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('noexpire.syntax', $command->getSyntaxKey());
        self::assertSame('noexpire.help', $command->getHelpKey());
        self::assertSame(66, $command->getOrder());
        self::assertSame('noexpire.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::NOEXPIRE, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['Target', 'ON']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function repliesSyntaxErrorWhenActionInvalid(): void
    {
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Target', 'INVALID']));

        self::assertFalse($outcome->success);
        self::assertSame(['error.syntax [%syntax%: noexpire.syntax]'], $messages);
    }

    #[Test]
    public function mapsOnOptionAndPresentsSuccess(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNoexpireNick $dto): bool => 'TargetNick' === $dto->nickname && $dto->noexpire,
        ))->willReturn(SetNoexpireNickResult::success('TargetNick', true));

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'ON']));

        self::assertTrue($outcome->success);
        self::assertSame(['noexpire.success_on [%nickname%: TargetNick]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('TargetNick', $outcome->auditData->target);
        self::assertSame('ON', $outcome->auditData->extra['option'] ?? null);
    }

    #[Test]
    public function mapsOffOptionAndPresentsSuccess(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (SetNoexpireNick $dto): bool => 'TargetNick' === $dto->nickname && !$dto->noexpire,
        ))->willReturn(SetNoexpireNickResult::success('TargetNick', false));

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'off']));

        self::assertTrue($outcome->success);
        self::assertSame(['noexpire.success_off [%nickname%: TargetNick]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('TargetNick', $outcome->auditData->target);
        self::assertSame('OFF', $outcome->auditData->extra['option'] ?? null);
    }

    #[Test]
    public function presentsNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(SetNoexpireNickResult::notRegistered('TargetNick'));

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'ON']));

        self::assertFalse($outcome->success);
        self::assertSame(['noexpire.not_registered [%nickname%: TargetNick]'], $messages);
    }

    #[Test]
    public function presentsForbidden(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(SetNoexpireNickResult::forbidden('TargetNick'));

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'ON']));

        self::assertFalse($outcome->success);
        self::assertSame(['noexpire.forbidden [%nickname%: TargetNick]'], $messages);
    }

    #[Test]
    public function presentsSuspended(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(SetNoexpireNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(SetNoexpireNickResult::suspended('TargetNick'));

        $command = new NoexpireCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick', 'ON']));

        self::assertFalse($outcome->success);
        self::assertSame(['noexpire.suspended [%nickname%: TargetNick]'], $messages);
    }

    /**
     * @param string[] $messages
     * @param string[] $args
     */
    private function createContext(?SenderView $sender, array &$messages, array $args): NickServContext
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslationInterface::class);
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
            'NOEXPIRE',
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
