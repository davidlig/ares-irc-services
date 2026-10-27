<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\DependencyInjection;

use App\Bootstrap\DependencyInjection\NonNegativeIntegerEnvVarProcessor;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

#[CoversClass(NonNegativeIntegerEnvVarProcessor::class)]
final class NonNegativeIntegerEnvVarProcessorTest extends TestCase
{
    public function testDeclaredType(): void
    {
        self::assertSame(['non_negative_int' => 'int'], NonNegativeIntegerEnvVarProcessor::getProvidedTypes());
    }

    #[DataProvider('validHours')]
    public function testPreservesValidHours(int|string $raw, int $expected): void
    {
        $processor = new NonNegativeIntegerEnvVarProcessor();
        self::assertSame($expected, $processor->getEnv('non_negative_int', 'CHANSERV_REJECT_COOLDOWN', static fn (string $name): int|string => $raw));
    }

    public function testCompiledConfigurationRejectsFractionalCooldown(): void
    {
        $oldEnv = $_ENV;
        $oldServer = $_SERVER;
        $_ENV['CHANSERV_REJECT_COOLDOWN'] = $_SERVER['CHANSERV_REJECT_COOLDOWN'] = '1.5';
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('CHANSERV_REJECT_COOLDOWN must be a non-negative integer.');
            $kernel->getContainer()->getParameter('chanserv.reject_cooldown_hours');
        } finally {
            $kernel->shutdown();
            $_ENV = $oldEnv;
            $_SERVER = $oldServer;
        }
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function validHours(): iterable
    {
        yield 'indefinite' => ['0', 0];
        yield 'default hours' => ['24', 24];
        yield 'integer parameter fallback' => [24, 24];
    }

    #[DataProvider('invalidHours')]
    public function testRejectsInvalidHoursWithoutSilentlyChangingTheirMeaning(mixed $raw): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CHANSERV_REJECT_COOLDOWN must be a non-negative integer.');
        new NonNegativeIntegerEnvVarProcessor()->getEnv('non_negative_int', 'CHANSERV_REJECT_COOLDOWN', static fn (string $name): mixed => $raw);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidHours(): iterable
    {
        yield 'fraction' => ['1.5'];
        yield 'negative fraction must not become indefinite' => ['-0.5'];
        yield 'negative integer' => ['-1'];
        yield 'exponent' => ['1e2'];
        yield 'overflow' => [(string) PHP_INT_MAX . '0'];
        yield 'not an integer' => ['hours'];
        yield 'empty' => [''];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'float' => [1.5];
    }
}
