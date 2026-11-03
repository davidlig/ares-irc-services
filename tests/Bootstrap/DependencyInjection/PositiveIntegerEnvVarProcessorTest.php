<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\DependencyInjection;

use App\Bootstrap\DependencyInjection\PositiveIntegerEnvVarProcessor;
use App\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

#[CoversClass(PositiveIntegerEnvVarProcessor::class)]
final class PositiveIntegerEnvVarProcessorTest extends TestCase
{
    public function testDeclaredType(): void
    {
        self::assertSame(['positive_int' => 'int'], PositiveIntegerEnvVarProcessor::getProvidedTypes());
    }

    #[DataProvider('validHours')]
    public function testPreservesValidHours(int|string $raw, int $expected): void
    {
        $processor = new PositiveIntegerEnvVarProcessor();
        self::assertSame($expected, $processor->getEnv('positive_int', 'NICKSERV_PASSWORD_MIN_LENGTH', static fn (string $name): int|string => $raw));
    }

    public function testCompiledConfigurationRejectsFractionalCooldown(): void
    {
        $oldEnv = $_ENV;
        $oldServer = $_SERVER;
        $_ENV['NICKSERV_PASSWORD_MIN_LENGTH'] = $_SERVER['NICKSERV_PASSWORD_MIN_LENGTH'] = '1.5';
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('NICKSERV_PASSWORD_MIN_LENGTH must be a positive integer.');
            $kernel->getContainer()->getParameter('nickserv.password_min_length');
        } finally {
            $kernel->shutdown();
            $_ENV = $oldEnv;
            $_SERVER = $oldServer;
        }
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function validHours(): iterable
    {
        yield 'one' => ['1', 1];
        yield 'default hours' => ['24', 24];
        yield 'integer parameter fallback' => [24, 24];
    }

    #[DataProvider('invalidHours')]
    public function testRejectsInvalidHoursWithoutSilentlyChangingTheirMeaning(mixed $raw): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NICKSERV_PASSWORD_MIN_LENGTH must be a positive integer.');
        new PositiveIntegerEnvVarProcessor()->getEnv('positive_int', 'NICKSERV_PASSWORD_MIN_LENGTH', static fn (string $name): mixed => $raw);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidHours(): iterable
    {
        yield 'zero' => ['0'];
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
