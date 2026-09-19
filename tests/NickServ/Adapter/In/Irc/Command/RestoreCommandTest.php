<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RestoreCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Restore\RestoreNick;
use App\NickServ\Application\UseCase\Restore\RestoreNickHandlerInterface;
use App\NickServ\Application\UseCase\Restore\RestoreNickResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_scalar;

#[CoversClass(RestoreCommand::class)]
final class RestoreCommandTest extends TestCase
{
    #[Test]
    public function exposesRestoreMetadata(): void
    {
        $command = new RestoreCommand($this->createStub(RestoreNickHandlerInterface::class));

        self::assertSame('RESTORE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('restore.syntax', $command->getSyntaxKey());
        self::assertSame('restore.help', $command->getHelpKey());
        self::assertSame(72, $command->getOrder());
        self::assertSame('restore.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::RESTORE, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(RestoreNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new RestoreCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['Target']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsTargetAndSenderToRestoreNick(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(RestoreNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RestoreNick $dto): bool => 'TargetNick' === $dto->nickname && 'OperNick' === $dto->operatorNick,
        ))->willReturn(RestoreNickResult::success('TargetNick'));

        $command = new RestoreCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertTrue($outcome->success);
        self::assertSame(['restore.success [%nickname%: TargetNick]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('TargetNick', $outcome->auditData->target);
    }

    #[Test]
    public function presentsNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(RestoreNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(RestoreNickResult::notRegistered('TargetNick'));

        $command = new RestoreCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertFalse($outcome->success);
        self::assertSame(['restore.not_registered [%nickname%: TargetNick]'], $messages);
    }

    #[Test]
    public function presentsNotPendingDeletion(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createMock(RestoreNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(RestoreNickResult::notPendingDeletion('TargetNick'));

        $command = new RestoreCommand($handler);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertFalse($outcome->success);
        self::assertSame(['restore.not_pending_deletion [%nickname%: TargetNick]'], $messages);
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
            'RESTORE',
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
