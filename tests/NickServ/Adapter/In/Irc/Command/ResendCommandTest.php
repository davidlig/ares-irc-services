<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\ResendCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandlerInterface;
use App\NickServ\Application\UseCase\Resend\ResendVerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function inet_pton;

#[CoversClass(ResendCommand::class)]
final class ResendCommandTest extends TestCase
{
    #[Test]
    public function exposesResendMetadata(): void
    {
        $command = new ResendCommand($this->createStub(ResendVerificationHandlerInterface::class), verificationTokenTtlSeconds: 3600);

        self::assertSame('RESEND', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('resend.syntax', $command->getSyntaxKey());
        self::assertSame('resend.help', $command->getHelpKey());
        self::assertSame('resend.example', $command->getExampleKey());
        self::assertSame(4, $command->getOrder());
        self::assertSame('resend.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame(['duration_seconds' => 3600], $command->getHelpParams());
    }

    #[Test]
    public function exposesConfiguredSecondsForHelp(): void
    {
        $command = new ResendCommand($this->createStub(ResendVerificationHandlerInterface::class), verificationTokenTtlSeconds: 300);

        self::assertSame(['duration_seconds' => 300], $command->getHelpParams());
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(ResendVerificationHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new ResendCommand($handler, verificationTokenTtlSeconds: 3600);
        $messages = [];
        $command->execute($this->createContext(null, $messages));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsSenderAndLanguageToTypedInput(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', base64_encode((string) inet_pton('192.0.2.2')));

        $handler = $this->createMock(ResendVerificationHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (ResendVerification $input): bool => 'Alice' === $input->nickname
                && 'es' === $input->language
                && 'Alice' === $input->actorNickname
                && '192.0.2.2' === $input->sourceIp))
            ->willReturn(ResendVerificationResult::noPending());

        $command = new ResendCommand($handler, verificationTokenTtlSeconds: 3600);
        $messages = [];
        $command->execute($this->createContext($sender, $messages));

        self::assertSame(['resend.no_pending'], $messages);
    }

    #[Test]
    public function preservesRepliesForUnknownAndMalformedSourceIps(): void
    {
        foreach ([['*', null], ['???notbase64???', '???notbase64???']] as [$source, $expectedIp]) {
            $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', $source);
            $handler = $this->createMock(ResendVerificationHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->with(self::callback(
                static fn (ResendVerification $input): bool => 'Alice' === $input->actorNickname && $expectedIp === $input->sourceIp,
            ))->willReturn(ResendVerificationResult::noPending());

            $command = new ResendCommand($handler, verificationTokenTtlSeconds: 3600);
            $messages = [];
            $command->execute($this->createContext($sender, $messages));

            self::assertSame(['resend.no_pending'], $messages);
            self::assertStringNotContainsString('???notbase64???', implode(' ', $messages));
        }
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('outcomes')]
    public function presentsOutcomes(ResendVerificationResult $result, string $expectedKey, array $expectedParams): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip');
        $handler = $this->createStub(ResendVerificationHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $command = new ResendCommand($handler, verificationTokenTtlSeconds: 3600);
        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];
        $command->execute($this->createContext($sender, $calls, captureParams: true));

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

    /** @return iterable<string, array{ResendVerificationResult, string, array<string, mixed>}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [ResendVerificationResult::success('alice@example.com'), 'resend.success', ['%email%' => 'alice@example.com']];
        yield 'disabled' => [ResendVerificationResult::disabled(), 'resend.disabled', []];
        yield 'no pending' => [ResendVerificationResult::noPending(), 'resend.no_pending', []];
        yield 'throttled' => [ResendVerificationResult::throttled(65), 'resend.throttled', ['%minutes%' => '2']];
        yield 'mail failed' => [ResendVerificationResult::mailDeliveryFailed(), 'error.mail_failed', []];
    }

    /**
     * @param list<mixed>  $messages
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        array &$messages,
        array $args = [],
        bool $captureParams = false,
    ): NickServContext {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'RESEND',
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
