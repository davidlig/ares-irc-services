<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\RenameCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Rename\RenameNick;
use App\NickServ\Application\UseCase\Rename\RenameNickHandlerInterface;
use App\NickServ\Application\UseCase\Rename\RenameNickResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RenameCommand::class)]
final class RenameCommandTest extends TestCase
{
    #[Test]
    public function exposesRenameMetadata(): void
    {
        $command = new RenameCommand(
            $this->createStub(RenameNickHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(LoggerInterface::class),
            guestPrefix: 'Guest-',
        );

        self::assertSame('RENAME', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('rename.syntax', $command->getSyntaxKey());
        self::assertSame('rename.help', $command->getHelpKey());
        self::assertSame(65, $command->getOrder());
        self::assertSame('rename.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::RENAME, $command->getRequiredPermission());
        self::assertSame(['%prefix%' => 'Guest-'], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(RenameNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new RenameCommand(
            $handler,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(LoggerInterface::class),
        );

        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['target']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsTargetAndSenderToTypedInput(): void
    {
        $sender = new SenderView('UID_OPER', 'OperNick', 'oper', 'box', 'cloak', 'ip');
        $targetUser = new SenderView('UID_TARGET', 'TargetNick', 'ident', 'host', 'cloak', 'ip_target');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('TargetNick')->willReturn($targetUser);

        $handler = $this->createMock(RenameNickHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (RenameNick $input): bool => 'TargetNick' === $input->targetNick
                && 'UID_TARGET' === $input->targetUid
                && 'ident' === $input->targetIdent
                && 'host' === $input->targetHostname
                && 'ip_target' === $input->targetIp
                && 'OperNick' === $input->operatorNick))
            ->willReturn(RenameNickResult::notOnline('TargetNick'));

        $command = new RenameCommand(
            $handler,
            $userLookup,
            $this->createStub(LoggerInterface::class),
        );

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['TargetNick']));

        self::assertFalse($outcome->success);
        self::assertSame(['rename.not_online'], $messages);
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('rejectionPresentations')]
    public function presentsRejections(RenameNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $sender = new SenderView('UID1', 'Oper', 'oper', 'box', 'cloak', 'ip');
        $handler = $this->createStub(RenameNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $command = new RenameCommand(
            $handler,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(LoggerInterface::class),
        );

        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];
        $outcome = $command->execute($this->createContext($sender, $calls, ['Target'], captureParams: true));

        self::assertFalse($outcome->success);
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

    /** @return iterable<string, array{RenameNickResult, string, array<string, mixed>}> */
    public static function rejectionPresentations(): iterable
    {
        yield 'not online' => [RenameNickResult::notOnline('Target'), 'rename.not_online', ['%nickname%' => 'Target']];
        yield 'cannot rename root' => [RenameNickResult::cannotRenameRoot('RootUser'), 'rename.cannot_rename_root', ['%nickname%' => 'RootUser']];
        yield 'cannot rename oper' => [RenameNickResult::cannotRenameOper('OperUser'), 'rename.cannot_rename_oper', ['%nickname%' => 'OperUser']];
        yield 'cannot rename service' => [RenameNickResult::cannotRenameService('NickServ'), 'rename.cannot_rename_service', ['%nickname%' => 'NickServ']];
    }

    #[Test]
    public function presentsSuccessLogsAndReturnsAuditData(): void
    {
        $sender = new SenderView('UID_OPER', 'OperNick', 'oper', 'box', 'cloak', 'ip');
        $targetUser = new SenderView('UID_TARGET', 'Trouble', 'bad', 'isp.org', 'cloak', '1.2.3.4');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetUser);

        $result = RenameNickResult::success(
            targetNick: 'Trouble',
            targetUid: 'UID_TARGET',
            targetHost: 'bad@isp.org',
            targetIp: '1.2.3.4',
            newNick: 'Guest-1234567',
        );

        $handler = $this->createStub(RenameNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('User renamed via RENAME command', [
                'operator' => 'OperNick',
                'target_nick' => 'Trouble',
                'target_uid' => 'UID_TARGET',
            ]);

        $command = new RenameCommand($handler, $userLookup, $logger);

        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Trouble']));

        self::assertTrue($outcome->success);
        self::assertSame(['rename.success'], $messages);

        $audit = $outcome->auditData;
        self::assertNotNull($audit);
        self::assertSame('Trouble', $audit->target);
        self::assertSame('bad@isp.org', $audit->targetHost);
        self::assertSame('1.2.3.4', $audit->targetIp);
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
    ): NickServContext {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
            $messages[] = $captureParams ? [$key, $params] : $key;

            return $key;
        });

        return new NickServContext(
            $sender,
            null,
            'RENAME',
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
