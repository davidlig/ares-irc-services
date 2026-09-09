<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Protocol;

use App\Bootstrap\Protocol\OperServRawCommandInterceptorBridge;
use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptionFailure;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServRawCommandInterceptorBridge::class)]
final class OperServRawCommandInterceptorBridgeTest extends TestCase
{
    #[Test]
    public function leavesCommandsNotOwnedByTheSelectedProtocolUnhandled(): void
    {
        self::assertNull($this->bridge(RawCommandInterception::notHandled())->intercept(['PING']));
    }

    #[Test]
    public function mapsAProtocolOwnedExecution(): void
    {
        $result = $this->bridge(RawCommandInterception::executed('DB INS'))->intercept(['DB', '*', 'INS']);

        self::assertNotNull($result);
        self::assertSame(RawCommandExecutionOutcome::Intercepted, $result->outcome);
        self::assertSame('DB INS', $result->operation);
    }

    #[Test]
    #[DataProvider('failures')]
    public function mapsProtocolFailuresToOperServResults(
        RawCommandInterceptionFailure $failure,
        RawCommandExecutionOutcome $expected,
    ): void {
        $result = $this->bridge(RawCommandInterception::rejected(
            $failure,
            resourceType: 'type',
            resourceIdentifier: 'resource',
        ))->intercept(['opaque']);

        self::assertNotNull($result);
        self::assertSame($expected, $result->outcome);
        self::assertSame('type', $result->resourceType);
        self::assertSame('resource', $result->resourceIdentifier);
    }

    /** @return iterable<string, array{RawCommandInterceptionFailure, RawCommandExecutionOutcome}> */
    public static function failures(): iterable
    {
        yield 'target' => [RawCommandInterceptionFailure::TargetInvalid, RawCommandExecutionOutcome::TargetInvalid];
        yield 'syntax' => [RawCommandInterceptionFailure::SyntaxInvalid, RawCommandExecutionOutcome::SyntaxInvalid];
        yield 'unsupported' => [RawCommandInterceptionFailure::Unsupported, RawCommandExecutionOutcome::Unsupported];
        yield 'type' => [RawCommandInterceptionFailure::ResourceTypeInvalid, RawCommandExecutionOutcome::ResourceTypeInvalid];
        yield 'resource' => [RawCommandInterceptionFailure::ResourceIdentifierInvalid, RawCommandExecutionOutcome::ResourceIdentifierInvalid];
        yield 'value' => [RawCommandInterceptionFailure::ValueInvalid, RawCommandExecutionOutcome::ValueInvalid];
        yield 'rejected' => [RawCommandInterceptionFailure::Rejected, RawCommandExecutionOutcome::Failed];
    }

    private function bridge(RawCommandInterception $interception): OperServRawCommandInterceptorBridge
    {
        $interceptor = $this->createStub(RawCommandInterceptorInterface::class);
        $interceptor->method('intercept')->willReturn($interception);

        return new OperServRawCommandInterceptorBridge($interceptor);
    }
}
