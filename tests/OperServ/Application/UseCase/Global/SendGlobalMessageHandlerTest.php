<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\Global;

use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\UseCase\Global\SendGlobalMessage;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageHandler;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageOutcome;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageResult;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalMessageMask::class)]
#[CoversClass(NetworkUser::class)]
#[CoversClass(OperatorAccountData::class)]
#[CoversClass(SendGlobalMessage::class)]
#[CoversClass(SendGlobalMessageHandler::class)]
#[CoversClass(SendGlobalMessageResult::class)]
final class SendGlobalMessageHandlerTest extends TestCase
{
    #[Test]
    public function sendsFromExistingServiceAndRedactsMessageFromAudit(): void
    {
        $network = new GlobalTestTransport(['NickServ' => 'SVC001']);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(static function (CommandAuditRecord $record) use ($network): bool {
            self::assertSame([['SVC001', 'password=never-audit', MessageDelivery::NonInteractive]], $network->serviceMessages);
            self::assertSame('GLOBAL', $record->operation);
            self::assertSame('NickServ', $record->target);
            self::assertNull($record->reason);
            self::assertSame(['delivery' => 'non_interactive', 'recipient_count' => 2, 'sender_kind' => 'service'], $record->metadata);

            return true;
        }));
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network, $audit)
            ->handle(new SendGlobalMessage('Oper', 'NickServ', MessageDelivery::NonInteractive, 'password=never-audit', new DateTimeImmutable('2026-09-08T10:00:00+00:00')));

        self::assertSame(SendGlobalMessageOutcome::Sent, $result->outcome);
        self::assertSame([['SVC001', 'password=never-audit', MessageDelivery::NonInteractive]], $network->serviceMessages);
        self::assertSame(2, $result->recipientCount);
    }

    #[Test]
    public function sendsFromServiceResolvedAfterParsingTheMask(): void
    {
        $network = new GlobalTestTransport(['NickServ' => 'SVC001']);

        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'NickServ!service@services.test', MessageDelivery::NonInteractive, 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::Sent, $result->outcome);
        self::assertSame('NickServ', $result->senderNickname);
        self::assertSame([['SVC001', 'hello', MessageDelivery::NonInteractive]], $network->serviceMessages);
    }

    #[Test]
    public function reportsUnavailableServiceDeliveryBeforeAndAfterParsingTheMask(): void
    {
        $network = new GlobalTestTransport(['NickServ' => 'SVC001'], serviceCount: null);
        $handler = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network);

        $direct = $handler->handle(new SendGlobalMessage('Oper', 'NickServ', MessageDelivery::NonInteractive, 'hello', new DateTimeImmutable()));
        $parsed = $handler->handle(new SendGlobalMessage('Oper', 'NickServ!service@services.test', MessageDelivery::NonInteractive, 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::NetworkUnavailable, $direct->outcome);
        self::assertSame('NickServ', $direct->senderNickname);
        self::assertSame(SendGlobalMessageOutcome::NetworkUnavailable, $parsed->outcome);
        self::assertSame('NickServ', $parsed->senderNickname);
    }

    #[Test]
    public function validatesMaskAndRejectsConnectedOrRegisteredPseudoNickname(): void
    {
        $invalid = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'invalid', MessageDelivery::NonInteractive, 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::InvalidMask, $invalid->outcome);

        $connected = $this->handler(new GlobalTestUsers(['Temp' => new NetworkUser('U1', 'Temp', 'i', 'h', '', false, false)]), new GlobalTestAccounts(), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', MessageDelivery::NonInteractive, 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NicknameConnected, $connected->outcome);

        $registered = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(['temp' => 9]), new GlobalTestTransport())
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', MessageDelivery::NonInteractive, 'message', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NicknameRegistered, $registered->outcome);
    }

    #[Test]
    public function sendsFromTemporaryClientAndReportsUnavailableNetwork(): void
    {
        $network = new GlobalTestTransport(temporaryCount: 3);
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', MessageDelivery::Interactive, 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::Sent, $result->outcome);
        self::assertSame('Temp', $result->senderNickname);
        self::assertSame([['Temp!ident@host.test', 'hello', MessageDelivery::Interactive, 'Oper']], $network->temporaryMessages);

        $unavailable = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), new GlobalTestTransport(temporaryCount: null))
            ->handle(new SendGlobalMessage('Oper', 'Temp!ident@host.test', MessageDelivery::Interactive, 'hello', new DateTimeImmutable()));
        self::assertSame(SendGlobalMessageOutcome::NetworkUnavailable, $unavailable->outcome);
    }

    #[Test]
    public function rejectsUnsupportedMessageTypeBeforeCallingNetwork(): void
    {
        $network = new GlobalTestTransport();
        $result = $this->handler(new GlobalTestUsers(), new GlobalTestAccounts(), $network)
            ->handle(new SendGlobalMessage('Oper', 'NickServ', null, 'hello', new DateTimeImmutable()));

        self::assertSame(SendGlobalMessageOutcome::InvalidMessageType, $result->outcome);
        self::assertSame([], $network->serviceMessages);
    }

    private function handler(
        NetworkUserLookup $users,
        OperatorAccountLookup $accounts,
        GlobalMessageTransport $network,
        ?CommandAuditRecorder $audit = null,
    ): SendGlobalMessageHandler {
        return new SendGlobalMessageHandler($users, $accounts, $network, $audit ?? $this->createStub(CommandAuditRecorder::class));
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
    /** @var list<array{string, string, MessageDelivery}> */
    public array $serviceMessages = [];

    /** @var list<array{string, string, MessageDelivery, string}> */
    public array $temporaryMessages = [];

    /** @param array<string, string> $serviceUids */
    public function __construct(private array $serviceUids = [], private ?int $serviceCount = 2, private ?int $temporaryCount = 2) {}

    public function serviceUidForNickname(string $nickname): ?string
    {
        return $this->serviceUids[$nickname] ?? null;
    }

    public function broadcastFromService(string $senderUid, string $message, MessageDelivery $delivery): ?int
    {
        $this->serviceMessages[] = [$senderUid, $message, $delivery];

        return $this->serviceCount;
    }

    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, MessageDelivery $delivery, string $actorNickname): ?int
    {
        $this->temporaryMessages[] = [(string) $sender, $message, $delivery, $actorNickname];

        return $this->temporaryCount;
    }
}
