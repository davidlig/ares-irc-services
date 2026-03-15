<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Entity;

use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use const PASSWORD_DEFAULT;

#[CoversClass(RegisteredNick::class)]
final class RegisteredNickTest extends TestCase
{
    #[Test]
    public function createPendingSetsInitialState(): void
    {
        $expiresAt = new DateTimeImmutable('+1 hour');

        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            $expiresAt,
        );

        self::assertSame(NickStatus::Pending, $nick->getStatus());
        self::assertSame('Nick', $nick->getNickname());
        self::assertSame('nick', $nick->getNicknameLower());
        self::assertTrue($nick->isPending());
        self::assertFalse($nick->isRegistered());
        self::assertSame('hash', $nick->getPasswordHash());
        self::assertSame('user@example.com', $nick->getEmail());
        self::assertSame('en', $nick->getLanguage());
        self::assertInstanceOf(DateTimeImmutable::class, $nick->getRegisteredAt());
        self::assertSame($expiresAt->getTimestamp(), $nick->getExpiresAt()?->getTimestamp());
        self::assertNull($nick->getReason());
    }

    #[Test]
    public function createPendingWithInvalidEmailThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email');

        RegisteredNick::createPending(
            'Nick',
            'hash',
            'not-an-email',
            'en',
            new DateTimeImmutable('+1 hour'),
        );
    }

    #[Test]
    public function createForbiddenSetsForbiddenState(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Reason', 'es');

        self::assertSame(NickStatus::Forbidden, $nick->getStatus());
        self::assertSame('BadNick', $nick->getNickname());
        self::assertSame('badnick', $nick->getNicknameLower());
        self::assertTrue($nick->isForbidden());
        self::assertNull($nick->getPasswordHash());
        self::assertNull($nick->getEmail());
        self::assertSame('es', $nick->getLanguage());
        self::assertNull($nick->getRegisteredAt());
        self::assertSame('Reason', $nick->getReason());
    }

    #[Test]
    public function activateTransitionsFromPendingToRegistered(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $nick->activate();

        self::assertSame(NickStatus::Registered, $nick->getStatus());
        self::assertFalse($nick->isPending());
        self::assertTrue($nick->isRegistered());
        self::assertNull($nick->getExpiresAt());
    }

    #[Test]
    public function suspendAndUnsuspend(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $nick->activate();
        $nick->suspend('Abuse');

        self::assertTrue($nick->isSuspended());
        self::assertSame('Abuse', $nick->getReason());

        $nick->unsuspend();

        self::assertSame(NickStatus::Registered, $nick->getStatus());
        self::assertTrue($nick->isRegistered());
        self::assertNull($nick->getReason());
    }

    #[Test]
    public function changeLanguageRejectsUnsupportedLanguage(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported language');

        $nick->changeLanguage('fr');
    }

    #[Test]
    public function changeTimezoneWithEmptyOrWhitespaceClearsTimezone(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $nick->changeTimezone('UTC');
        self::assertSame('UTC', $nick->getTimezone());

        $nick->changeTimezone('');
        self::assertNull($nick->getTimezone());

        $nick->changeTimezone('Europe/Madrid');
        self::assertSame('Europe/Madrid', $nick->getTimezone());

        $nick->changeTimezone('   ');
        self::assertNull($nick->getTimezone());
    }

    #[Test]
    public function verifyPasswordReturnsFalseWhenPasswordHashIsNull(): void
    {
        $nick = RegisteredNick::createForbidden('ForbiddenNick', 'Abuse', 'en');

        self::assertNull($nick->getPasswordHash());
        self::assertFalse($nick->verifyPassword('any'));
    }

    #[Test]
    public function expiredChecksPendingAndTimestamp(): void
    {
        $expired = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($expired->isExpired());

        $notExpired = RegisteredNick::createPending(
            'Nick2',
            'hash',
            'user2@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertFalse($notExpired->isExpired());
    }

    #[Test]
    public function languageValidationRejectsUnsupportedLanguages(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RegisteredNick::createForbidden('Nick', 'Reason', 'fr');
    }

    #[Test]
    public function changeEmailValidatesFormat(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $this->expectException(InvalidArgumentException::class);

        $nick->changeEmail('not-an-email');
    }

    #[Test]
    public function changeLanguageNormalizesAndValidates(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'EN',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertSame('en', $nick->getLanguage());

        $nick->changeLanguage('Es');

        self::assertSame('es', $nick->getLanguage());
    }

    #[Test]
    public function markSeenAndQuitMessage(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertNull($nick->getLastSeenAt());
        self::assertNull($nick->getLastQuitMessage());

        $nick->markSeen();
        $nick->updateQuitMessage('Quit');

        self::assertInstanceOf(DateTimeImmutable::class, $nick->getLastSeenAt());
        self::assertSame('Quit', $nick->getLastQuitMessage());
    }

    #[Test]
    public function privacyAndMessageTypeFlags(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertFalse($nick->isPrivate());
        self::assertSame('NOTICE', $nick->getMessageType());

        $nick->switchPrivate(true);
        $nick->switchMsg(true);

        self::assertTrue($nick->isPrivate());
        self::assertSame('PRIVMSG', $nick->getMessageType());
    }

    #[Test]
    public function vhostAndTimezoneChanges(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $nick->changeVhost('vhost.example.com');
        self::assertSame('vhost.example.com', $nick->getVhost());

        $nick->changeVhost(null);
        self::assertNull($nick->getVhost());

        $nick->changeTimezone('UTC');
        self::assertSame('UTC', $nick->getTimezone());

        $nick->changeTimezone(null);
        self::assertNull($nick->getTimezone());

        $this->expectException(InvalidArgumentException::class);

        $nick->changeTimezone('Not/AZone');
    }

    #[Test]
    public function changePasswordAndVerify(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            password_hash('old', PASSWORD_DEFAULT),
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        self::assertTrue($nick->verifyPassword('old'));
        self::assertFalse($nick->verifyPassword('other'));

        $nick->changePassword(password_hash('new', PASSWORD_DEFAULT));

        self::assertTrue($nick->verifyPassword('new'));
        self::assertFalse($nick->verifyPassword('old'));
    }

    #[Test]
    public function changePasswordWithHasherUsesGivenService(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $hasher = new class implements PasswordHasherInterface {
            public function hash(string $plainPassword): string
            {
                return 'hashed-' . $plainPassword;
            }

            public function verify(string $plainPassword, string $hash): bool
            {
                return $hash === $this->hash($plainPassword);
            }
        };

        $nick->changePasswordWithHasher('secret', $hasher);

        self::assertSame('hashed-secret', $nick->getPasswordHash());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
        );

        $reflection = new ReflectionClass($nick);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, 99);

        self::assertSame(99, $nick->getId());
    }
}
