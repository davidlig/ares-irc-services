<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Security;

use App\Infrastructure\IRC\Security\SensitiveDataRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveDataRedactor::class)]
final class SensitiveDataRedactorTest extends TestCase
{
    #[Test]
    public function redactNickServCommandMasksRegisterPassword(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('REGISTER mypassword user@example.com');

        self::assertSame('REGISTER ****** user@example.com', $result);
    }

    #[Test]
    public function redactNickServCommandMasksIdentifyPassword(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('IDENTIFY NickServPassword123');

        self::assertSame('IDENTIFY ******', $result);
    }

    #[Test]
    public function redactNickServCommandMasksIdentifyWithNickAndPassword(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('IDENTIFY MyNick MyPassword');

        self::assertSame('IDENTIFY MyNick ******', $result);
    }

    #[Test]
    public function redactNickServCommandMasksSetPassword(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('SET PASSWORD newSecret123');

        self::assertSame('SET PASSWORD ******', $result);
    }

    #[Test]
    public function redactNickServCommandPreservesSetOtherOptions(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('SET EMAIL user@example.com');

        self::assertSame('SET EMAIL user@example.com', $result);
    }

    #[Test]
    public function redactNickServCommandHandlesCaseInsensitiveRegister(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('register secretpass test@test.com');

        self::assertSame('register ****** test@test.com', $result);
    }

    #[Test]
    public function redactNickServCommandHandlesCaseInsensitiveIdentify(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('identify MyNick MySecretPass');

        self::assertSame('identify MyNick ******', $result);
    }

    #[Test]
    public function redactNickServCommandHandlesCaseInsensitiveSetPassword(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('set password MyNewPassword');

        self::assertSame('set password ******', $result);
    }

    #[Test]
    public function redactNickServCommandPreservesOtherCommands(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('INFO NickName');

        self::assertSame('INFO NickName', $result);
    }

    #[Test]
    public function redactNickServCommandHandlesEmptyString(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('');

        self::assertSame('', $result);
    }

    #[Test]
    public function redactNickServCommandHandlesRegisterWithoutEmail(): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand('REGISTER mypassword');

        self::assertSame('REGISTER ******', $result);
    }

    #[Test]
    #[DataProvider('provideCommandsToRedact')]
    public function redactNickServCommandCorrectlyRedacts(string $input, string $expected): void
    {
        $result = SensitiveDataRedactor::redactNickServCommand($input);

        self::assertSame($expected, $result);
    }

    public static function provideCommandsToRedact(): iterable
    {
        yield 'REGISTER with password and email' => [
            'REGISTER password email@test.com',
            'REGISTER ****** email@test.com',
        ];

        yield 'IDENTIFY with one arg' => [
            'IDENTIFY password',
            'IDENTIFY ******',
        ];

        yield 'IDENTIFY with two args' => [
            'IDENTIFY nickname password',
            'IDENTIFY nickname ******',
        ];

        yield 'SET PASSWORD' => [
            'SET PASSWORD newpass',
            'SET PASSWORD ******',
        ];

        yield 'SET EMAIL (no masking)' => [
            'SET EMAIL test@test.com',
            'SET EMAIL test@test.com',
        ];

        yield 'INFO (no masking)' => [
            'INFO TestUser',
            'INFO TestUser',
        ];

        yield 'DROP (no masking)' => [
            'DROP TestChannel',
            'DROP TestChannel',
        ];

        yield 'REGISTER extra args' => [
            'REGISTER password email@test.com extra',
            'REGISTER ****** email@test.com extra',
        ];

        yield 'mixed case SET PASSWORD' => [
            'Set Password SecretPass',
            'Set Password ******',
        ];
    }
}
