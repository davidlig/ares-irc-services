<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security;

use App\Infrastructure\NickServ\Security\Argon2PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Argon2PasswordHasher::class)]
final class Argon2PasswordHasherTest extends TestCase
{
    private Argon2PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new Argon2PasswordHasher();
    }

    #[Test]
    public function hashProducesValidArgon2idHash(): void
    {
        $hash = $this->hasher->hash('myPlainPassword');

        self::assertStringStartsWith('$argon2id$', $hash);
    }

    #[Test]
    public function hashProducesDifferentHashesForSamePassword(): void
    {
        $hash1 = $this->hasher->hash('myPlainPassword');
        $hash2 = $this->hasher->hash('myPlainPassword');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function verifyReturnsTrueForCorrectPassword(): void
    {
        $hash = $this->hasher->hash('myPlainPassword');

        self::assertTrue($this->hasher->verify('myPlainPassword', $hash));
    }

    #[Test]
    public function verifyReturnsFalseForIncorrectPassword(): void
    {
        $hash = $this->hasher->hash('myPlainPassword');

        self::assertFalse($this->hasher->verify('wrongPassword', $hash));
    }

    #[Test]
    public function verifyReturnsFalseForInvalidHash(): void
    {
        self::assertFalse($this->hasher->verify('password', 'invalid-hash'));
    }

    #[Test]
    public function verifyReturnsFalseForEmptyPassword(): void
    {
        $hash = $this->hasher->hash('myPlainPassword');

        self::assertFalse($this->hasher->verify('', $hash));
    }

    #[Test]
    public function hashHandlesEmptyPassword(): void
    {
        $hash = $this->hasher->hash('');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($this->hasher->verify('', $hash));
    }

    #[Test]
    public function hashHandlesLongPassword(): void
    {
        $longPassword = str_repeat('a', 1000);
        $hash = $this->hasher->hash($longPassword);

        self::assertTrue($this->hasher->verify($longPassword, $hash));
    }

    #[Test]
    public function verifyIsCaseSensitive(): void
    {
        $hash = $this->hasher->hash('Password');

        self::assertFalse($this->hasher->verify('password', $hash));
        self::assertTrue($this->hasher->verify('Password', $hash));
    }

    #[Test]
    public function verifyWorksWithPrecomputedHash(): void
    {
        $hash = '$argon2id$v=19$m=65536,t=4,p=1$abcdefghijklmnopqrstuvwxyz$1234567890abcdefghij';

        self::assertFalse($this->hasher->verify('test', $hash));
    }
}
