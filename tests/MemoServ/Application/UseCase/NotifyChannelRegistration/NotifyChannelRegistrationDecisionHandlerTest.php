<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\NotifyChannelRegistration;

use App\MemoServ\Application\Port\Out\ChannelRegistrationMemoText;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\Port\Out\ServiceMemoSender;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecisionHandler;
use App\MemoServ\Domain\Entity\Memo;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

#[CoversClass(NotifyChannelRegistrationDecisionHandler::class)]
#[CoversClass(NotifyChannelRegistrationDecision::class)]
final class NotifyChannelRegistrationDecisionHandlerTest extends TestCase
{
    #[Test]
    public function storesEveryRenderedChunkForRequesterWithoutMemoPreferences(): void
    {
        $occurredAt = new DateTimeImmutable('2026-10-26T10:00:00+00:00');
        $accounts = $this->createMock(MemoUserAccountPort::class);
        $accounts->expects(self::once())->method('findNicknameById')->with(12)->willReturn('Requester');
        $accounts->expects(self::once())->method('getLanguage')->with(12)->willReturn('es');

        $texts = $this->createMock(ChannelRegistrationMemoText::class);
        $texts->expects(self::once())->method('render')->with(self::isInstanceOf(NotifyChannelRegistrationDecision::class), 'es')
            ->willReturn(['Decision part one', 'Decision part two']);

        $saved = [];
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('save')->willReturnCallback(static function (Memo $memo) use (&$saved): void {
            $saved[] = $memo;
        });

        $sender = $this->createMock(ServiceMemoSender::class);
        $sender->expects(self::once())->method('getSenderName')->willReturn('ChannelBot');
        $handler = new NotifyChannelRegistrationDecisionHandler($accounts, $repository, $texts, $sender);
        $handler->handle(new NotifyChannelRegistrationDecision(
            44,
            '#approved',
            12,
            7,
            'Operator',
            false,
            'reason',
            null,
            $occurredAt,
        ));

        self::assertCount(2, $saved);
        self::assertSame([12, 12], array_map(static fn (Memo $memo): ?int => $memo->getTargetNickId(), $saved));
        self::assertSame([null, null], array_map(static fn (Memo $memo): ?int => $memo->getSenderNickId(), $saved));
        self::assertSame(['ChannelBot', 'ChannelBot'], array_map(static fn (Memo $memo): ?string => $memo->getSenderServiceName(), $saved));
        self::assertSame(['Decision part one', 'Decision part two'], array_map(static fn (Memo $memo): string => $memo->getMessage(), $saved));
        self::assertSame([$occurredAt, $occurredAt], array_map(static fn (Memo $memo): DateTimeImmutable => $memo->getCreatedAt(), $saved));
    }

    #[Test]
    public function doesNotCreateMemosWhenRequesterAccountWasDeleted(): void
    {
        $accounts = $this->createStub(MemoUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn(null);
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $texts = $this->createMock(ChannelRegistrationMemoText::class);
        $texts->expects(self::never())->method('render');

        $sender = $this->createStub(ServiceMemoSender::class);
        $sender->method('getSenderName')->willReturn('ChannelBot');
        $handler = new NotifyChannelRegistrationDecisionHandler($accounts, $repository, $texts, $sender);
        $handler->handle(new NotifyChannelRegistrationDecision(
            44,
            '#approved',
            12,
            7,
            'Operator',
            true,
            null,
            null,
            new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
        ));
    }

    #[DataProvider('invalidRenderedMessages')]
    public function testAllChunksAreValidatedBeforeAnyMemoIsStored(string $invalid): void
    {
        $accounts = $this->createStub(MemoUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Requester');
        $accounts->method('getLanguage')->willReturn('es');
        $texts = $this->createStub(ChannelRegistrationMemoText::class);
        $texts->method('render')->willReturn(['First valid chunk', $invalid]);
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $sender = $this->createStub(ServiceMemoSender::class);
        $sender->method('getSenderName')->willReturn('ChannelBot');
        $handler = new NotifyChannelRegistrationDecisionHandler($accounts, $repository, $texts, $sender);

        $this->expectException(InvalidArgumentException::class);
        $handler->handle(new NotifyChannelRegistrationDecision(44, '#channel', 12, 7, 'Operator', true, null, null, new DateTimeImmutable('2026-10-26T10:00:00+00:00')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRenderedMessages(): iterable
    {
        yield 'too many bytes' => [str_repeat('a', 256)];
        yield 'invalid UTF-8' => ["\xFF"];
    }
}
