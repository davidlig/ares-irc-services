<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Policy;

use App\OperServ\Domain\Policy\GlineMaskPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(GlineMaskPolicy::class)]
final class GlineMaskPolicyTest extends TestCase
{
    #[Test]
    public function cannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(GlineMaskPolicy::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
    }

    #[Test]
    #[DataProvider('inputMasks')]
    public function validatesRawInput(string $mask, bool $valid): void
    {
        self::assertSame($valid, GlineMaskPolicy::isValidInput($mask));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function inputMasks(): iterable
    {
        yield 'empty' => ['', false];
        yield 'nick user host mask' => ['nick!ident@host', false];
        yield 'nickname' => ['Nick', true];
        yield 'user host mask' => ['ident@host.test', true];
    }

    #[Test]
    #[DataProvider('nicknameMasks')]
    public function identifiesNicknameMasks(string $mask, bool $nickname): void
    {
        self::assertSame($nickname, GlineMaskPolicy::isNickname($mask));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nicknameMasks(): iterable
    {
        yield 'empty' => ['', false];
        yield 'user host' => ['ident@host.test', false];
        yield 'full user mask' => ['Nick!ident@host', false];
        yield 'nickname' => ['Nick', true];
    }

    #[Test]
    #[DataProvider('globalMasks')]
    public function identifiesGlobalMasks(string $mask, bool $global): void
    {
        self::assertSame($global, GlineMaskPolicy::isGlobal($mask));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function globalMasks(): iterable
    {
        yield 'single wildcard' => ['*', true];
        yield 'complete wildcard' => ['*!*@*', true];
        yield 'user host wildcards' => ['*@*', true];
        yield 'optional bang pattern' => ['**@***', true];
        yield 'case and whitespace' => ['  *@*  ', true];
        yield 'specific mask' => ['ident@host.test', false];
    }

    #[Test]
    #[DataProvider('safeMasks')]
    public function requiresAReasonablySpecificUserOrHost(string $mask, bool $safe): void
    {
        self::assertSame($safe, GlineMaskPolicy::isSafe($mask));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function safeMasks(): iterable
    {
        yield 'bang is forbidden' => ['nick!ident@host.test', false];
        yield 'host separator required' => ['nickname', false];
        yield 'alphanumeric user is specific' => ['ident@*', true];
        yield 'wildcard user with short host' => ['*@a.c', false];
        yield 'wildcard user with four host alphanumerics' => ['*@a.bcd', true];
        yield 'empty user with specific host' => ['@host', true];
    }
}
