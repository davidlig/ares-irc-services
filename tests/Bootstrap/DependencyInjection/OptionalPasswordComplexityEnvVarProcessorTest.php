<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\DependencyInjection;

use App\Bootstrap\DependencyInjection\OptionalPasswordComplexityEnvVarProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

#[CoversClass(OptionalPasswordComplexityEnvVarProcessor::class)]
final class OptionalPasswordComplexityEnvVarProcessorTest extends TestCase
{
    public function testOnlyMissingVariableDisablesComplexity(): void
    {
        $processor = new OptionalPasswordComplexityEnvVarProcessor();
        self::assertSame('none', $processor->getEnv('optional_password_complexity', 'NICKSERV_PASSWORD_COMPLEXITY', static fn () => throw new EnvNotFoundException('NICKSERV_PASSWORD_COMPLEXITY')));
        self::assertSame(['optional_password_complexity' => 'string'], $processor::getProvidedTypes());
    }

    #[DataProvider('levels')]
    public function testAcceptsExplicitLevels(string $level): void
    {
        self::assertSame($level, new OptionalPasswordComplexityEnvVarProcessor()->getEnv('optional_password_complexity', 'NICKSERV_PASSWORD_COMPLEXITY', static fn (): string => $level));
    }

    /** @return iterable<array{string}> */
    public static function levels(): iterable
    {
        yield ['low'];
        yield ['mid'];
        yield ['high'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsExplicitInvalidValues(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NICKSERV_PASSWORD_COMPLEXITY must be low, mid or high');
        new OptionalPasswordComplexityEnvVarProcessor()->getEnv('optional_password_complexity', 'NICKSERV_PASSWORD_COMPLEXITY', static fn (): mixed => $value);
    }

    /** @return iterable<array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield ['none'];
        yield [''];
        yield ['LOW'];
        yield [' low '];
        yield ['unknown'];
        yield [true];
        yield [null];
        yield [8];
    }
}
