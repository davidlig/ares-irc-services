<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRawDatabaseMutationAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbRawDatabaseMutationAdapter::class)]
final class UnrealUdbRawDatabaseMutationAdapterTest extends TestCase
{
    #[Test]
    public function forwardsSuccessfulMutationsWithoutExposingTheLegacyAuditLine(): void
    {
        $handler = $this->createMock(UdbRawCommandHandlerInterface::class);
        $handler->expects(self::once())->method('ins')->with('N::nick::vhost', 'cloak.example')
            ->willReturn(UdbRawCommandResult::success('DB * INS N::nick::vhost cloak.example'));
        $handler->expects(self::once())->method('del')->with('N::nick::vhost')
            ->willReturn(UdbRawCommandResult::success('DB * DEL N::nick::vhost'));
        $adapter = new UnrealUdbRawDatabaseMutationAdapter($this->provider($handler));

        self::assertTrue($adapter->isAvailable());
        self::assertTrue($adapter->insert('N::nick::vhost', 'cloak.example')->successful);
        self::assertTrue($adapter->delete('N::nick::vhost')->successful);
    }

    #[Test]
    public function reportsAProtocolNeutralFailureWhenNoHandlerIsActive(): void
    {
        $adapter = new UnrealUdbRawDatabaseMutationAdapter($this->provider(null));

        self::assertFalse($adapter->isAvailable());
        self::assertSame(RawDatabaseMutationFailure::Rejected, $adapter->insert('N::nick', 'value')->failure);
        self::assertSame(RawDatabaseMutationFailure::Rejected, $adapter->delete('N::nick')->failure);
    }

    #[Test]
    #[DataProvider('legacyFailures')]
    public function mapsOnlyRecognizedLegacyErrorsToTypedSafeFields(
        UdbRawCommandResult $legacyResult,
        RawDatabaseMutationFailure $expectedFailure,
        ?string $expectedRecordType,
        ?string $expectedRecordPath,
    ): void {
        $handler = $this->createStub(UdbRawCommandHandlerInterface::class);
        $handler->method('ins')->willReturn($legacyResult);

        $result = new UnrealUdbRawDatabaseMutationAdapter($this->provider($handler))->insert('ignored', 'ignored');

        self::assertFalse($result->successful);
        self::assertSame($expectedFailure, $result->failure);
        self::assertSame($expectedRecordType, $result->recordType);
        self::assertSame($expectedRecordPath, $result->recordPath);
    }

    /** @return iterable<string, array{UdbRawCommandResult, RawDatabaseMutationFailure, ?string, ?string}> */
    public static function legacyFailures(): iterable
    {
        yield 'record type' => [
            UdbRawCommandResult::error('raw.udb.invalid_block', ['%block%' => 'X', 'payload' => 'discarded']),
            RawDatabaseMutationFailure::UnsupportedRecordType,
            'X',
            null,
        ];
        yield 'record path' => [
            UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => 'N::bad', 'payload' => 'discarded']),
            RawDatabaseMutationFailure::InvalidRecordPath,
            null,
            'N::bad',
        ];
        yield 'record value' => [
            UdbRawCommandResult::error('raw.udb.invalid_value', ['%path%' => 'N::nick::pass <redacted>']),
            RawDatabaseMutationFailure::InvalidRecordValue,
            null,
            'N::nick::pass <redacted>',
        ];
        yield 'non string detail' => [
            UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => ['not-safe']]),
            RawDatabaseMutationFailure::InvalidRecordPath,
            null,
            null,
        ];
        yield 'unknown error' => [
            UdbRawCommandResult::error('some.protocol.error', ['payload' => 'discarded']),
            RawDatabaseMutationFailure::Rejected,
            null,
            null,
        ];
    }

    private function provider(?UdbRawCommandHandlerInterface $handler): UdbRawCommandHandlerProviderInterface
    {
        $provider = $this->createStub(UdbRawCommandHandlerProviderInterface::class);
        $provider->method('getActiveHandler')->willReturn($handler);

        return $provider;
    }
}
