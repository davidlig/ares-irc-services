<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\DatabaseMutationFailure;
use App\Irc\Application\Port\In\DatabaseMutationResult;
use App\Irc\Application\Port\In\ProtocolDatabaseMutation;
use App\OperServ\Adapter\Out\Irc\ProtocolRawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolRawDatabaseMutationHandler::class)]
#[CoversClass(DatabaseMutationResult::class)]
#[CoversClass(RawDatabaseMutationResult::class)]
final class ProtocolRawDatabaseMutationHandlerTest extends TestCase
{
    #[Test]
    public function delegatesAvailabilityAndSuccessfulMutations(): void
    {
        $protocol = $this->createMock(ProtocolDatabaseMutation::class);
        $protocol->expects(self::once())->method('isAvailable')->willReturn(true);
        $protocol->expects(self::once())->method('insert')->with('N::Alice::vhost', 'alice.example.test')->willReturn(DatabaseMutationResult::success());
        $protocol->expects(self::once())->method('delete')->with('N::Alice::vhost')->willReturn(DatabaseMutationResult::success());
        $handler = new ProtocolRawDatabaseMutationHandler($protocol);

        self::assertTrue($handler->isAvailable());
        self::assertTrue($handler->insert('N::Alice::vhost', 'alice.example.test')->successful);
        self::assertTrue($handler->delete('N::Alice::vhost')->successful);
    }

    #[Test]
    #[DataProvider('failures')]
    public function mapsProtocolNeutralFailuresBackToTheOperServContract(
        DatabaseMutationFailure $source,
        RawDatabaseMutationFailure $expected,
    ): void {
        $protocol = $this->createStub(ProtocolDatabaseMutation::class);
        $protocol->method('insert')->willReturn(DatabaseMutationResult::failure(
            $source,
            recordType: 'N',
            path: 'N::Alice::pass <redacted>',
            reason: 'not exposed by the OperServ contract',
        ));

        $result = new ProtocolRawDatabaseMutationHandler($protocol)->insert('ignored', 'ignored');

        self::assertFalse($result->successful);
        self::assertSame($expected, $result->failure);
        self::assertSame('N', $result->recordType);
        self::assertSame('N::Alice::pass <redacted>', $result->recordPath);
    }

    /** @return iterable<string, array{DatabaseMutationFailure, RawDatabaseMutationFailure}> */
    public static function failures(): iterable
    {
        yield 'unsupported record type' => [DatabaseMutationFailure::UnsupportedRecordType, RawDatabaseMutationFailure::UnsupportedRecordType];
        yield 'invalid path' => [DatabaseMutationFailure::InvalidPath, RawDatabaseMutationFailure::InvalidRecordPath];
        yield 'invalid value' => [DatabaseMutationFailure::InvalidValue, RawDatabaseMutationFailure::InvalidRecordValue];
        yield 'rejected' => [DatabaseMutationFailure::Rejected, RawDatabaseMutationFailure::Rejected];
    }
}
