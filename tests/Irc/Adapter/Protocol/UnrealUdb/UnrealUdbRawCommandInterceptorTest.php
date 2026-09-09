<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\RawCommandInterceptionFailure;
use App\Irc\Adapter\Protocol\RawCommandInterceptionOutcome;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRawCommandInterceptor;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbRawCommandInterceptor::class)]
final class UnrealUdbRawCommandInterceptorTest extends TestCase
{
    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('unhandledCommands')]
    public function ignoresCommandsOutsideItsGrammar(array $arguments): void
    {
        $handler = $this->createMock(UdbRawCommandHandlerInterface::class);
        $handler->expects(self::never())->method('ins');
        $handler->expects(self::never())->method('del');

        $result = new UnrealUdbRawCommandInterceptor($handler)->intercept($arguments);

        self::assertSame(RawCommandInterceptionOutcome::NotHandled, $result->outcome);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unhandledCommands(): iterable
    {
        yield 'too few arguments' => [['DB', '*']];
        yield 'different command' => [['PING', '*', 'INS']];
        yield 'different DB subcommand' => [['DB', '*', 'LIST']];
    }

    #[Test]
    public function rejectsACommandForOneServer(): void
    {
        $result = $this->interceptor()->intercept(['DB', '001', 'INS', 'N::nick', 'value']);

        self::assertSame(RawCommandInterceptionOutcome::Rejected, $result->outcome);
        self::assertSame(RawCommandInterceptionFailure::TargetInvalid, $result->failure);
        self::assertSame('001', $result->resourceIdentifier);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('invalidSyntax')]
    public function rejectsInvalidMutationSyntax(array $arguments): void
    {
        $result = $this->interceptor()->intercept($arguments);

        self::assertSame(RawCommandInterceptionOutcome::Rejected, $result->outcome);
        self::assertSame(RawCommandInterceptionFailure::SyntaxInvalid, $result->failure);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidSyntax(): iterable
    {
        yield 'insert without value' => [['DB', '*', 'INS', 'N::nick']];
        yield 'insert without path' => [['DB', '*', 'INS', '  ', 'value']];
        yield 'delete without path' => [['DB', '*', 'DEL']];
        yield 'delete with value' => [['DB', '*', 'DEL', 'N::nick', 'value']];
        yield 'delete with blank path' => [['DB', '*', 'DEL', ' ']];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('insertValues')]
    public function decodesAndDelegatesInsertValues(array $arguments, string $expectedValue): void
    {
        $handler = $this->createMock(UdbRawCommandHandlerInterface::class);
        $handler->expects(self::once())->method('ins')->with('N::nick::vhost', $expectedValue)
            ->willReturn(UdbRawCommandResult::success('safe audit'));

        $result = new UnrealUdbRawCommandInterceptor($handler)->intercept($arguments);

        self::assertSame(RawCommandInterceptionOutcome::Executed, $result->outcome);
        self::assertSame('DB INS', $result->operation);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function insertValues(): iterable
    {
        yield 'colon trailing parameter' => [['db', '*', 'ins', 'N::nick::vhost', ':cloak.example'], 'cloak.example'];
        yield 'quoted value' => [['DB', '*', 'INS', 'N::nick::vhost', '"cloak', 'example"'], 'cloak example'];
        yield 'unquoted value' => [['DB', '*', 'INS', 'N::nick::vhost', 'cloak', 'example'], 'cloak example'];
        yield 'single quote remains literal' => [['DB', '*', 'INS', 'N::nick::vhost', '"'], '"'];
    }

    #[Test]
    public function delegatesDelete(): void
    {
        $handler = $this->createMock(UdbRawCommandHandlerInterface::class);
        $handler->expects(self::once())->method('del')->with('N::nick')
            ->willReturn(UdbRawCommandResult::success('safe audit'));

        $result = new UnrealUdbRawCommandInterceptor($handler)->intercept(['DB', '*', 'DEL', 'N::nick']);

        self::assertSame(RawCommandInterceptionOutcome::Executed, $result->outcome);
        self::assertSame('DB DEL', $result->operation);
    }

    #[Test]
    #[DataProvider('unsupportedMutations')]
    public function rejectsRecognizedButUnsupportedMutations(string $subcommand): void
    {
        $result = $this->interceptor()->intercept(['DB', '*', $subcommand]);

        self::assertSame(RawCommandInterceptionOutcome::Rejected, $result->outcome);
        self::assertSame(RawCommandInterceptionFailure::Unsupported, $result->failure);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedMutations(): iterable
    {
        yield 'drop' => ['DRP'];
        yield 'options' => ['OPT'];
    }

    #[Test]
    #[DataProvider('handlerFailures')]
    public function mapsHandlerFailuresToThePublicBoundary(
        UdbRawCommandResult $handlerResult,
        RawCommandInterceptionFailure $expectedFailure,
        ?string $expectedType,
        ?string $expectedIdentifier,
    ): void {
        $handler = $this->createStub(UdbRawCommandHandlerInterface::class);
        $handler->method('del')->willReturn($handlerResult);

        $result = new UnrealUdbRawCommandInterceptor($handler)->intercept(['DB', '*', 'DEL', 'N::nick']);

        self::assertSame(RawCommandInterceptionOutcome::Rejected, $result->outcome);
        self::assertSame($expectedFailure, $result->failure);
        self::assertSame($expectedType, $result->resourceType);
        self::assertSame($expectedIdentifier, $result->resourceIdentifier);
    }

    /** @return iterable<string, array{UdbRawCommandResult, RawCommandInterceptionFailure, ?string, ?string}> */
    public static function handlerFailures(): iterable
    {
        yield 'block' => [UdbRawCommandResult::error('raw.udb.invalid_block', ['%block%' => 'X']), RawCommandInterceptionFailure::ResourceTypeInvalid, 'X', null];
        yield 'path' => [UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => 'bad']), RawCommandInterceptionFailure::ResourceIdentifierInvalid, null, 'bad'];
        yield 'value' => [UdbRawCommandResult::error('raw.udb.invalid_value', ['%path%' => 'bad']), RawCommandInterceptionFailure::ValueInvalid, null, 'bad'];
        yield 'store' => [UdbRawCommandResult::error('raw.udb.error'), RawCommandInterceptionFailure::Rejected, null, null];
        yield 'malformed parameter' => [UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => 42]), RawCommandInterceptionFailure::ResourceIdentifierInvalid, null, null];
    }

    private function interceptor(): UnrealUdbRawCommandInterceptor
    {
        return new UnrealUdbRawCommandInterceptor($this->createStub(UdbRawCommandHandlerInterface::class));
    }
}
