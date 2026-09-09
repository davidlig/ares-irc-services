<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\IdentifyCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Identify\IdentifyNick;
use App\NickServ\Application\UseCase\Identify\IdentifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Identify\IdentifyNickResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IdentifyCommand::class)]
final class IdentifyCommandTest extends TestCase
{
    #[Test]
    public function exposesIdentifyMetadata(): void
    {
        $command = new IdentifyCommand(
            $this->createStub(IdentifyNickHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            new NickServClientKeyResolver(),
        );

        self::assertSame('IDENTIFY', $command->getName());
        self::assertSame(['ID'], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('identify.syntax', $command->getSyntaxKey());
        self::assertSame('identify.help', $command->getHelpKey());
        self::assertSame(2, $command->getOrder());
        self::assertSame('identify.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(IdentifyNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new IdentifyCommand(
            $handler,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            new NickServClientKeyResolver(),
        );

        $messages = [];
        $command->execute($this->createContext(null, $messages, ['target', 'pass']));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsContextToTypedInput(): void
    {
        $sender = new SenderView('UID1', 'SenderNick', 'ident', 'host', 'cloak', 'ip123', true);

        $handler = $this->createMock(IdentifyNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (IdentifyNick $input): bool => 'TargetNick' === $input->nickname
                && 'secretpass' === $input->password
                && 'ip:ip123' === $input->clientKey
                && 'UID1' === $input->senderUid
                && 'SenderNick' === $input->senderNick
                && true === $input->senderIsIdentified))
            ->willReturn(IdentifyNickResult::alreadyIdentified('TargetNick'));

        $command = new IdentifyCommand(
            $handler,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            new NickServClientKeyResolver(),
        );

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['TargetNick', 'secretpass']));

        self::assertSame(['identify.already_identified'], $messages);
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('nonSuccessPresentations')]
    public function presentsNonSuccessOutcomes(IdentifyNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $handler = $this->createStub(IdentifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $command = new IdentifyCommand(
            $handler,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            new NickServClientKeyResolver(),
        );

        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip');
        $command->execute($this->createContext($sender, $calls, ['Target', 'pass'], captureParams: true));

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

    /** @return iterable<string, array{IdentifyNickResult, string, array<string, mixed>}> */
    public static function nonSuccessPresentations(): iterable
    {
        yield 'already identified' => [IdentifyNickResult::alreadyIdentified('Alice'), 'identify.already_identified', ['%nickname%' => 'Alice']];
        yield 'locked out' => [IdentifyNickResult::lockedOut(65), 'identify.locked_out', ['%minutes%' => '2']];
        yield 'not registered' => [IdentifyNickResult::notRegistered('Alice'), 'identify.not_registered', ['%nickname%' => 'Alice']];
        yield 'pending' => [IdentifyNickResult::pending('Alice'), 'identify.pending', ['%nickname%' => 'Alice']];
        yield 'suspended with reason' => [IdentifyNickResult::suspended('Alice', 'Abuse'), 'identify.suspended', ['%nickname%' => 'Alice', '%reason%' => 'Abuse']];
        yield 'forbidden' => [IdentifyNickResult::forbidden('Alice'), 'identify.forbidden', ['%nickname%' => 'Alice']];
        yield 'pending deletion' => [IdentifyNickResult::pendingDeletion('Alice'), 'identify.pending_deletion', ['%nickname%' => 'Alice']];
        yield 'invalid credentials' => [IdentifyNickResult::invalidCredentials(), 'identify.invalid_credentials', []];
    }

    #[Test]
    public function presentsSuccessReleasingGhostAndSettingVhost(): void
    {
        $sender = new SenderView('UID1', 'AltNick', 'ident', 'host', 'cloak', 'ip', serverSid: '001');
        $ghostHolder = new SenderView('UID2', 'Alice', 'ident2', 'host2', 'cloak2', 'ip2');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Alice')->willReturn($ghostHolder);

        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->method('peek')->willReturn(false);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('killUser')->with('UID2', 'identify.kill_reason');
        $notifier->expects(self::once())->method('forceNick')->with('UID1', 'Alice');
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', 'Alice');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'custom.vhost', '001');

        $handler = $this->createStub(IdentifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn(IdentifyNickResult::success('Alice', 'custom.vhost', 'en'));

        $command = new IdentifyCommand($handler, $userLookup, $pendingRegistry, new NickServClientKeyResolver());

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Alice', 'pass'], notifier: $notifier));

        self::assertSame(['identify.kill_reason', 'identify.ghost_released', 'identify.success'], $messages);
    }

    #[Test]
    public function presentsSuccessWhenSameNickAndPendingRestoreWithoutVhost(): void
    {
        $sender = new SenderView('UID1', 'Alice', 'ident', 'host', 'cloak', 'ip', serverSid: '001');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Alice')->willReturn($sender);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('peek')->with('UID1')->willReturn(true);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('killUser');
        $notifier->expects(self::once())->method('forceNick')->with('UID1', 'Alice');
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', 'Alice');
        $notifier->expects(self::never())->method('setUserVhost');

        $handler = $this->createStub(IdentifyNickHandlerInterface::class);
        $handler->method('handle')->willReturn(IdentifyNickResult::success('Alice', null, 'es'));

        $command = new IdentifyCommand($handler, $userLookup, $pendingRegistry, new NickServClientKeyResolver());

        $messages = [];
        $command->execute($this->createContext($sender, $messages, ['Alice', 'pass'], notifier: $notifier));

        self::assertSame(['identify.success'], $messages);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'IDENTIFY',
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
