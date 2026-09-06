<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\VerifyCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifyCommand::class)]
final class VerifyCommandTest extends TestCase
{
    #[Test]
    public function exposesVerifyMetadata(): void
    {
        $command = new VerifyCommand($this->createStub(VerifyNickHandlerInterface::class));

        self::assertSame('VERIFY', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('verify.syntax', $command->getSyntaxKey());
        self::assertSame('verify.help', $command->getHelpKey());
        self::assertSame(3, $command->getOrder());
        self::assertSame('verify.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(VerifyNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new VerifyCommand($handler);
        $messages = [];
        $command->execute($this->createContext(null, $messages, ['token123']));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsSenderAndTokenToTypedInput(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip');

        $handler = $this->createMock(VerifyNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (VerifyNick $input): bool => 'Alice' === $input->nickname
                && 'tok123' === $input->token
                && 'UID1' === $input->senderUid))
            ->willReturn(VerifyNickResult::invalidToken());

        $command = new VerifyCommand($handler);
        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['tok123']));

        self::assertSame(['verify.invalid_token'], $messages);
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('outcomes')]
    public function presentsOutcomes(VerifyNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createStub(VerifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        if (null !== $result->nickname) {
            $notifier->expects(self::once())->method('setUserAccount')->with('UID1', 'Alice');
        } else {
            $notifier->expects(self::never())->method('setUserAccount');
        }

        $command = new VerifyCommand($handler);
        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];
        $command->execute($this->createContext($sender, $calls, ['tok123'], captureParams: true, notifier: $notifier));

        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertIsArray($call);
        self::assertSame($expectedKey, $call[0]);
        $params = $call[1];
        self::assertIsArray($params);
        foreach ($expectedParams as $key => $value) {
            self::assertSame($value, $params[$key] ?? null);
        }
    }

    /** @return iterable<string, array{VerifyNickResult, string, array<string, mixed>}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [VerifyNickResult::success('Alice'), 'verify.success', ['%nickname%' => 'Alice']];
        yield 'no pending' => [VerifyNickResult::noPending(), 'verify.no_pending', []];
        yield 'invalid token' => [VerifyNickResult::invalidToken(), 'verify.invalid_token', []];
    }

    /**
     * @param list<mixed>  $messages
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        array &$messages,
        array $args,
        bool $captureParams = false,
        ?NickServNotifierInterface $notifier = null,
    ): NickServContext {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'VERIFY',
            $args,
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
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
