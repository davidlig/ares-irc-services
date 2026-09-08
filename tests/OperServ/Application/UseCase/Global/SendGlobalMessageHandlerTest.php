<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\Global;

use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\UseCase\Global\GlobalMessageType;
use App\OperServ\Application\UseCase\Global\SendGlobalMessage;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageHandler;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageOutcome;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendGlobalMessageHandler::class)]
final class SendGlobalMessageHandlerTest extends TestCase
{
    #[Test]
    public function sendsFromExistingServiceAndRedactsMessageFromAudit(): void
    {
        $network = new GlobalTestTransport(['NickServ' => 'SVC001']);
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'NickServ', 'notice', 'password=never-audit', new DateTimeImmutable('2026-09-08T10:00:00+00:00')));

        self::assertSame(SendGlobalMessageOutcome::Sent, $result->outcome);
        self::assertSame([['SVC001', 'password=never-audit', GlobalMessageType::Notice]], $network->serviceMessages);
        self::assertSame(2, $result->recipientCount);
        self::assertNull($result->auditRecord?->reason);
        self::assertSame(['message_type' => 'NOTICE', 'recipient_count' => 2, 'sender_kind' => 'service'], $result->auditRecord?->metadata);
    }

    #[Test]
    public function validatesMaskAndRejectsConnectedOrRegisteredPseudoNickname(): void
    {
        $invalid = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'invalid', 'NOTICE', 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::InvalidMask, $invalid->outcome);

        $connected = $this->handler(new GlobalTestUsers(['Temp' => new NetworkUser('U1', 'Temp', 'i', 'h', '', false, false)]), new GlobalTestAccounts(), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', 'NOTICE', 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NicknameConnected, $connected->outcome);

        $registered = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(['temp' => 9]), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', 'NOTICE', 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NicknameRegistered, $registered->outcome);
    }

    #[Test]
    public function sendsFromTemporaryClientAndReportsUnavailableNetwork(): void
    {
        $network = new GlobalTestTransport(temporaryCount: 3);
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', 'PRIVMSG', 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::Sent, $result->outcome);
        self::assertSame('Temp', $result->senderNickname);
        self::assertSame([['Temp!ident@host.test', 'hello', GlobalMessageType::PrivateMessage, 'Oper']], $network->temporaryMessages);

        $unavailable = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), new GlobalTestTransport(temporaryCount: null))
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', 'PRIVMSG', 'hello', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NetworkUnavailable, $unavailable->outcome);
    }

    #[Test]
    public function rejectsUnsupportedMessageTypeBeforeCallingNetwork(): void
    {
        $network = new GlobalTestTransport();
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'NickServ', 'CTCP', 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::InvalidMessageType, $result->outcome);
        self::assertSame([], $network->serviceMessages);
    }

    private function handler(NetworkUserLookup $users, OperatorAccountLookup $accounts, GlobalMessageTransport $network): SendGlobalMessageHandler
    {
        return new SendGlobalMessageHandler($users, $accounts, $network);
    }
}

final readonly class GlobalTestUsers implements NetworkUserLookup
{
    /** @param array<string, NetworkUser> $users */
    public function __construct(private array $users = []) {}

    public function findByNickname(string $nickname): ?NetworkUser
    {
        return $this->users[$nickname] ?? null;
    }
}
final readonly class GlobalTestAccounts implements OperatorAccountLookup
{
    /** @param array<string, int> $ids */
    public function __construct(private array $ids = []) {}

    public function findIdByNickname(string $nickname): ?int
    {
        return $this->ids[$nickname] ?? null;
    }

    public function findNicknameById(int $id): ?string
    {
        return null;
    }

    public function findByNickname(string $nickname): ?OperatorAccountData
    {
        return null;
    }
}
final class GlobalTestTransport implements GlobalMessageTransport
{
    /** @var list<array{string, string, GlobalMessageType}> */
    public array $serviceMessages = [];

    /** @var list<array{string, string, GlobalMessageType, string}> */
    public array $temporaryMessages = [];

    /** @param array<string, string> $serviceUids */
    public function __construct(private array $serviceUids = [], private ?int $serviceCount = 2, private ?int $temporaryCount = 2) {}

    public function serviceUidForNickname(string $nickname): ?string
    {
        return $this->serviceUids[$nickname] ?? null;
    }

    public function broadcastFromService(string $senderUid, string $message, GlobalMessageType $messageType): ?int
    {
        $this->serviceMessages[] = [$senderUid, $message, $messageType];

        return $this->serviceCount;
    }

    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, GlobalMessageType $messageType, string $actorNickname): ?int
    {
        $this->temporaryMessages[] = [(string) $sender, $message, $messageType, $actorNickname];

        return $this->temporaryCount;
    }
}
