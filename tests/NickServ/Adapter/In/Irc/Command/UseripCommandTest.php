<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\UseripCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Userip\GetUserip;
use App\NickServ\Application\UseCase\Userip\GetUseripHandlerInterface;
use App\NickServ\Application\UseCase\Userip\GetUseripResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function base64_encode;
use function inet_pton;
use function is_scalar;

#[CoversClass(UseripCommand::class)]
final class UseripCommandTest extends TestCase
{
    #[Test]
    public function exposesUseripMetadata(): void
    {
        $command = new UseripCommand(
            $this->createStub(GetUseripHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertSame('USERIP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('userip.syntax', $command->getSyntaxKey());
        self::assertSame('userip.help', $command->getHelpKey());
        self::assertSame(60, $command->getOrder());
        self::assertSame('userip.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(NickServPermission::USERIP, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function returnsRejectedWhenSenderIsNull(): void
    {
        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $command = new UseripCommand($handler, $this->createStub(NetworkUserLookupPort::class));
        $messages = [];
        $outcome = $command->execute($this->createContext(null, $messages, ['Target']));

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function handlesTargetNotOnline(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('OfflineNick')->willReturn(null);

        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (GetUserip $dto): bool => 'OfflineNick' === $dto->nickname
                && null === $dto->ip
                && null === $dto->hostname,
        ))->willReturn(GetUseripResult::notOnline('OfflineNick'));

        $command = new UseripCommand($handler, $userLookup);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['OfflineNick']));

        self::assertFalse($outcome->success);
        self::assertSame(['userip.not_online [%nickname%: OfflineNick]'], $messages);
    }

    #[Test]
    public function handlesTargetOnlineWithIpv4(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $targetUser = new SenderView('UID2', 'OnlineNick', 'user', 'client.net', 'cloak', base64_encode((string) inet_pton('10.0.0.5')));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('OnlineNick')->willReturn($targetUser);

        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (GetUserip $dto): bool => 'OnlineNick' === $dto->nickname
                && '10.0.0.5' === $dto->ip
                && 'client.net' === $dto->hostname,
        ))->willReturn(GetUseripResult::success('OnlineNick', '10.0.0.5', 'client.net'));

        $command = new UseripCommand($handler, $userLookup);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['OnlineNick']));

        self::assertTrue($outcome->success);
        self::assertSame(['userip.result [%host%: client.net, %ip%: 10.0.0.5, %nickname%: OnlineNick]'], $messages);
        self::assertNotNull($outcome->auditData);
        self::assertSame('OnlineNick', $outcome->auditData->target);
        self::assertSame('client.net', $outcome->auditData->targetHost);
        self::assertSame('10.0.0.5', $outcome->auditData->targetIp);
    }

    #[Test]
    public function handlesTargetOnlineWithIpv6AndEdgeCases(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $ipv6Binary = (string) inet_pton('2001:db8::1');
        $targetUser = new SenderView('UID2', 'Ipv6Nick', 'user', 'client.org', 'cloak', base64_encode($ipv6Binary));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Ipv6Nick')->willReturn($targetUser);

        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (GetUserip $dto): bool => 'Ipv6Nick' === $dto->nickname
                && bin2hex($ipv6Binary) === $dto->ip,
        ))->willReturn(GetUseripResult::success('Ipv6Nick', bin2hex($ipv6Binary), 'client.org'));

        $command = new UseripCommand($handler, $userLookup);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['Ipv6Nick']));

        self::assertTrue($outcome->success);
    }

    #[Test]
    public function handlesTargetOnlineWithInvalidOrOddLengthBase64(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $targetUser = new SenderView('UID2', 'OddNick', 'user', 'client.org', 'cloak', '???notbase64???');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('OddNick')->willReturn($targetUser);

        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (GetUserip $dto): bool => 'OddNick' === $dto->nickname
                && '???notbase64???' === $dto->ip,
        ))->willReturn(GetUseripResult::success('OddNick', '???notbase64???', 'client.org'));

        $command = new UseripCommand($handler, $userLookup);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['OddNick']));

        self::assertTrue($outcome->success);
    }

    #[Test]
    public function handlesTargetOnlineWithNon4Non16ByteBase64(): void
    {
        $sender = new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', 'ip');
        $targetUser = new SenderView('UID2', 'EightByteNick', 'user', 'client.org', 'cloak', base64_encode('12345678'));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('EightByteNick')->willReturn($targetUser);

        $handler = $this->createMock(GetUseripHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (GetUserip $dto): bool => 'EightByteNick' === $dto->nickname
                && base64_encode('12345678') === $dto->ip,
        ))->willReturn(GetUseripResult::success('EightByteNick', 'raw', 'client.org'));

        $command = new UseripCommand($handler, $userLookup);
        $messages = [];
        $outcome = $command->execute($this->createContext($sender, $messages, ['EightByteNick']));

        self::assertTrue($outcome->success);
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
            'USERIP',
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
