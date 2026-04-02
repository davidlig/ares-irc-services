<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\ValueObject;

use App\Domain\OperServ\ValueObject\GlobalMessageMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(GlobalMessageMask::class)]
final class GlobalMessageMaskTest extends TestCase
{
    #[Test]
    public function fromStringValidMask(): void
    {
        $mask = GlobalMessageMask::fromString('GlobalBot!global@services.red');

        self::assertSame('GlobalBot', $mask->nickname);
        self::assertSame('global', $mask->ident);
        self::assertSame('services.red', $mask->vhost);
        self::assertSame('GlobalBot!global@services.red', (string) $mask);
    }

    #[Test]
    public function fromStringValidMaskWithDigits(): void
    {
        $mask = GlobalMessageMask::fromString('Bot123!bot_123@host.example.com');

        self::assertSame('Bot123', $mask->nickname);
        self::assertSame('bot_123', $mask->ident);
        self::assertSame('host.example.com', $mask->vhost);
    }

    #[Test]
    public function fromStringValidMaskWithSpecialChars(): void
    {
        $mask = GlobalMessageMask::fromString('[Bot]!bot@irc-server.net');

        self::assertSame('[Bot]', $mask->nickname);
        self::assertSame('bot', $mask->ident);
        self::assertSame('irc-server.net', $mask->vhost);
    }

    #[Test]
    public function fromStringMissingExclamation(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('GlobalBot@services.red');
    }

    #[Test]
    public function fromStringMissingAt(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('GlobalBot!global');
    }

    #[Test]
    public function fromStringEmptyNickname(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('!global@services.red');
    }

    #[Test]
    public function fromStringEmptyIdent(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('GlobalBot!@services.red');
    }

    #[Test]
    public function fromStringEmptyVhost(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('GlobalBot!global@');
    }

    #[Test]
    public function fromStringNicknameTooLong(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Nickname cannot exceed 30 characters');

        GlobalMessageMask::fromString('VeryLongNicknameThatExceedsThirtyCharacters!global@services.red');
    }

    #[Test]
    public function fromStringInvalidNicknameStart(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Nickname must start with');

        GlobalMessageMask::fromString('123Bot!global@services.red');
    }

    #[Test]
    public function fromStringInvalidNicknameChar(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Nickname must start with');

        GlobalMessageMask::fromString('Bot Name!global@services.red');
    }

    #[Test]
    public function fromStringIdentTooLong(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Ident cannot exceed 20 characters');

        GlobalMessageMask::fromString('Bot!very_long_identifier_name@services.red');
    }

    #[Test]
    public function fromStringIdentInvalidChar(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Ident can only contain');

        GlobalMessageMask::fromString('Bot!global#test@services.red');
    }

    #[Test]
    public function fromStringVhostTooLong(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Vhost cannot exceed 63 characters');

        GlobalMessageMask::fromString('Bot!global@this.is.a.very.long.hostname.that.exceeds.sixty.three.characters.test');
    }

    #[Test]
    public function fromStringVhostInvalid(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Vhost must be a valid hostname');

        GlobalMessageMask::fromString('Bot!global@-invalid.host');
    }

    #[Test]
    public function fromStringVhostStartsWithDot(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Vhost must be a valid hostname');

        GlobalMessageMask::fromString('Bot!global@.example.com');
    }

    #[Test]
    public function fromStringVhostEndsWithDot(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Vhost must be a valid hostname');

        GlobalMessageMask::fromString('Bot!global@example.com.');
    }

    #[Test]
    public function nicknameWithBrackets(): void
    {
        $mask = GlobalMessageMask::fromString('[Admin]!admin@services.red');

        self::assertSame('[Admin]', $mask->nickname);
    }

    #[Test]
    public function nicknameWithBraces(): void
    {
        $mask = GlobalMessageMask::fromString('{Bot}!bot@services.red');

        self::assertSame('{Bot}', $mask->nickname);
    }

    #[Test]
    public function nicknameWithPipe(): void
    {
        $mask = GlobalMessageMask::fromString('Bot|Test!bot@services.red');

        self::assertSame('Bot|Test', $mask->nickname);
    }

    #[Test]
    public function nicknameWithBackslash(): void
    {
        $mask = GlobalMessageMask::fromString('Bot\\Test!bot@services.red');

        self::assertSame('Bot\\Test', $mask->nickname);
    }

    #[Test]
    public function identWithDash(): void
    {
        $mask = GlobalMessageMask::fromString('Bot!bot-name@services.red');

        self::assertSame('bot-name', $mask->ident);
    }

    #[Test]
    public function fromStringNicknameStartsWithInvalidCharThrows(): void
    {
        // Testing empty nickname scenario - mask like "!bot@test.com" fails format validation
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('!bot@test.com');
    }

    #[Test]
    public function fromStringIdentStartsWithInvalidCharThrows(): void
    {
        // Testing ident validation - ident must match pattern but this tests an edge case
        // The mask format requires nick!ident@vhost, but we need a valid format first
        // Actually the mask passes regex but fails ident validation for length/start
        $this->expectException(ValueError::class);

        GlobalMessageMask::fromString('Bot!@test.com');
    }

    #[Test]
    public function fromStringVhostIsEmpty(): void
    {
        // The regex validation fails first with "Invalid mask format" for empty vhost
        // because the pattern requires at least one character after @
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('Invalid mask format');

        GlobalMessageMask::fromString('Bot!test@');
    }
}
