<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\NickServ\Adapter\Out\Security\PhpPasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

use const PASSWORD_BCRYPT;

#[CoversClass(PhpPasswordHasher::class)]
final class PhpPasswordHasherTest extends TestCase
{
    private PhpPasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PhpPasswordHasher();
    }

    #[Test]
    public function hashProducesFixedBcryptHash(): void
    {
        $hash = $this->hasher->hash('myPlainPassword');

        $info = password_get_info($hash);
        self::assertSame(PASSWORD_BCRYPT, $info['algo']);
        self::assertSame(12, $info['options']['cost']);
        self::assertSame(60, strlen($hash));
        self::assertStringStartsWith('$2y$12$', $hash);
    }

    #[Test]
    public function hashIsStableAcrossPhpDefaultChanges(): void
    {
        // The UDB projection depends on the stored format: bcrypt must stay
        // bcrypt even if PHP changes PASSWORD_DEFAULT in the future.
        $hash = $this->hasher->hash('myPlainPassword');

        self::assertStringStartsWith('$2y$', $hash);
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

        $info = password_get_info($hash);
        self::assertSame(PASSWORD_BCRYPT, $info['algo']);
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
