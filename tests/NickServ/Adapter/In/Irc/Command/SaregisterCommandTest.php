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
use App\NickServ\Application\UseCase\Saregister\Saregister;
use App\NickServ\Application\UseCase\Saregister\SaregisterHandlerInterface;
use App\NickServ\Application\UseCase\Saregister\SaregisterResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SaregisterCommand::class)]
#[CoversClass(Saregister::class)]
#[CoversClass(SaregisterResult::class)]
final class SaregisterCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new SaregisterCommand(new RecordingSaregisterHandler());

        self::assertSame('SAREGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(3, $command->getMinArgs());
        self::assertSame('saregister.syntax', $command->getSyntaxKey());
        self::assertSame('saregister.help', $command->getHelpKey());
        self::assertSame(73, $command->getOrder());
        self::assertSame('saregister.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(NickServPermission::SAREGISTER, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsIrcInputToTheUseCase(): void
    {
        $handler = new RecordingSaregisterHandler();
        $messages = [];

        new SaregisterCommand($handler)->execute($this->context($messages, withSender: true));

        self::assertNotNull($handler->last);
        self::assertSame('NewNick', $handler->last->nickname);
        self::assertSame('plain-secret', $handler->last->password);
        self::assertSame('user@example.com', $handler->last->email);
        self::assertSame('Oper', $handler->last->actorNickname);
        self::assertSame(42, $handler->last->actorAccountId);
    }

    #[Test]
    #[DataProvider('presentations')]
    public function presentsSemanticOutcomes(SaregisterResult $result, string $expectedKey, bool $expectedSuccess): void
    {
        $messages = [];

        $outcome = new SaregisterCommand(new RecordingSaregisterHandler($result))
            ->execute($this->context($messages, withSender: true));

        self::assertSame([$expectedKey], $messages);
        self::assertSame($expectedSuccess, $outcome->success);
    }

    /** @return iterable<string, array{SaregisterResult, string, bool}> */
    public static function presentations(): iterable
    {
        yield 'registered' => [SaregisterResult::registered('NewNick'), 'saregister.done', true];
        yield 'invalid email' => [SaregisterResult::invalidEmail(), 'register.invalid_email', false];
        yield 'email used' => [SaregisterResult::emailAlreadyUsed('a@b.test'), 'register.email_already_used', false];
        yield 'already registered' => [SaregisterResult::alreadyRegistered('Nick'), 'register.already_registered', false];
        yield 'already pending' => [SaregisterResult::alreadyPending('Nick'), 'register.already_pending', false];
        yield 'forbidden' => [SaregisterResult::forbidden('Nick'), 'register.forbidden', false];
        yield 'pending deletion' => [SaregisterResult::pendingDeletion('Nick'), 'register.pending_deletion', false];
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(SaregisterHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        $outcome = new SaregisterCommand($handler)->execute($this->context($messages, withSender: false));

        self::assertSame([], $messages);
        self::assertFalse($outcome->success);
    }

    /** @param list<mixed> $messages */
    private function context(array &$messages, bool $withSender): NickServContext
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key) use (&$messages): string {
            $messages[] = $key;

            return $key;
        });

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');

        $sender = $withSender
            ? new SenderView('001AAA', 'Oper', 'op', 'host', 'cloak', 'ip', true, true)
            : null;

        $account = null;
        if ($withSender) {
            $account = $this->createStub(RegisteredNick::class);
            $account->method('getId')->willReturn(42);
        }

        return new NickServContext(
            $sender,
            $account,
            'SAREGISTER',
            ['NewNick', 'plain-secret', 'user@example.com'],
            $notifier,
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

final class RecordingSaregisterHandler implements SaregisterHandlerInterface
{
    public ?Saregister $last = null;

    private SaregisterResult $result;

    public function __construct(?SaregisterResult $result = null)
    {
        $this->result = $result ?? SaregisterResult::registered('NewNick');
    }

    public function handle(Saregister $command): SaregisterResult
    {
        $this->last = $command;

        return $this->result;
    }
}
