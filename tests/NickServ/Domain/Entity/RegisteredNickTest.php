<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\Entity;

use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
            new DateTimeImmutable(),
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
        self::assertFalse($nick->isNoExpire());
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
            new DateTimeImmutable(),
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
            new DateTimeImmutable(),
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
            new DateTimeImmutable(),
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
    public function suspendWithExpiration(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $expiresAt = new DateTimeImmutable('+7 days');
        $nick->suspend('Abuse', $expiresAt);

        self::assertTrue($nick->isSuspended());
        self::assertSame('Abuse', $nick->getReason());
        self::assertSame($expiresAt->getTimestamp(), $nick->getSuspendedUntil()?->getTimestamp());
    }

    #[Test]
    public function suspendPermanent(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $nick->suspend('Permanent ban', null);

        self::assertTrue($nick->isSuspended());
        self::assertSame('Permanent ban', $nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function unsuspendClearsSuspendedUntil(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $nick->suspend('Abuse', new DateTimeImmutable('+7 days'));
        self::assertNotNull($nick->getSuspendedUntil());

        $nick->unsuspend();

        self::assertSame(NickStatus::Registered, $nick->getStatus());
        self::assertNull($nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenNotSuspended(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();

        self::assertFalse($nick->isCurrentlySuspended(new DateTimeImmutable()));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenPermanent(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $nick->suspend('Permanent ban', null);

        self::assertTrue($nick->isCurrentlySuspended(new DateTimeImmutable()));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenNotExpired(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $nick->suspend('Temporary ban', new DateTimeImmutable('+7 days'));

        self::assertTrue($nick->isCurrentlySuspended(new DateTimeImmutable()));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenExpired(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->activate();
        $nick->suspend('Expired ban', new DateTimeImmutable('-1 second'));

        self::assertFalse($nick->isCurrentlySuspended(new DateTimeImmutable()));
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
            new DateTimeImmutable(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported language');

        $nick->changeLanguage('xx');
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
            new DateTimeImmutable(),
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
    public function updateForbiddenReasonRejectsNonForbiddenNick(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update forbidden reason on a non-forbidden account.');

        $nick->updateForbiddenReason('New reason');
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
            new DateTimeImmutable(),
        );

        self::assertTrue($expired->isExpired(new DateTimeImmutable()));

        $notExpired = RegisteredNick::createPending(
            'Nick2',
            'hash',
            'user2@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertFalse($notExpired->isExpired(new DateTimeImmutable()));
    }

    #[Test]
    public function languageValidationRejectsUnsupportedLanguages(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RegisteredNick::createForbidden('Nick', 'Reason', 'xx');
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
            new DateTimeImmutable(),
        );

        $this->expectException(InvalidArgumentException::class);

        $nick->changeEmail('not-an-email');
    }

    #[Test]
    public function changeEmailAcceptsValidEmail(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->changeEmail('new@example.com');

        self::assertSame('new@example.com', $nick->getEmail());
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
            new DateTimeImmutable(),
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
            new DateTimeImmutable(),
        );

        self::assertNull($nick->getLastSeenAt());
        self::assertNull($nick->getLastQuitMessage());

        $nick->markSeen(new DateTimeImmutable());
        $nick->updateQuitMessage('Quit');

        self::assertInstanceOf(DateTimeImmutable::class, $nick->getLastSeenAt());
        self::assertSame('Quit', $nick->getLastQuitMessage());
    }

    #[Test]
    public function updateLastConnectionStoresIpAndHost(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertNull($nick->getLastConnectIp());
        self::assertNull($nick->getLastConnectHost());

        $nick->updateLastConnection('192.168.1.1', 'user.isp.example');

        self::assertSame('192.168.1.1', $nick->getLastConnectIp());
        self::assertSame('user.isp.example', $nick->getLastConnectHost());
    }

    #[Test]
    public function updateLastConnectionWithEmptyStringsStoresNull(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->updateLastConnection('192.168.1.1', 'user.isp.example');
        self::assertSame('192.168.1.1', $nick->getLastConnectIp());
        self::assertSame('user.isp.example', $nick->getLastConnectHost());

        $nick->updateLastConnection('', '');

        self::assertNull($nick->getLastConnectIp());
        self::assertNull($nick->getLastConnectHost());
    }

    #[Test]
    public function updateLastConnectionWithAsteriskStoresNull(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->updateLastConnection('192.168.1.1', 'user.isp.example');
        self::assertSame('192.168.1.1', $nick->getLastConnectIp());

        $nick->updateLastConnection('*', 'host.example');

        self::assertNull($nick->getLastConnectIp());
        self::assertSame('host.example', $nick->getLastConnectHost());
    }

    #[Test]
    public function updateLastConnectionWithEmptyHostStoresNull(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->updateLastConnection('192.168.1.1', '');

        self::assertSame('192.168.1.1', $nick->getLastConnectIp());
        self::assertNull($nick->getLastConnectHost());
    }

    #[Test]
    public function getLastConnectIpReturnsNullWhenNotSet(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertNull($nick->getLastConnectIp());
        self::assertNull($nick->getLastConnectHost());
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
            new DateTimeImmutable(),
        );

        self::assertFalse($nick->isPrivate());
        self::assertFalse($nick->prefersPrivateMessages());

        $nick->switchPrivate(true);
        $nick->switchMsg(true);

        self::assertTrue($nick->isPrivate());
        self::assertTrue($nick->prefersPrivateMessages());
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
            new DateTimeImmutable(),
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
    public function changePasswordStoresTheProvidedHash(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'old-hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertSame('old-hash', $nick->getPasswordHash());

        $nick->changePassword('new-hash');

        self::assertSame('new-hash', $nick->getPasswordHash());
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
            new DateTimeImmutable(),
        );

        $reflection = new ReflectionClass($nick);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, 99);

        self::assertSame(99, $nick->getId());
    }

    #[Test]
    public function noExpireDefaultsToFalse(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertFalse($nick->isNoExpire());
    }

    #[Test]
    public function setNoExpireTrueSetsFlag(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        self::assertFalse($nick->isNoExpire());

        $nick->changeNoExpire(true);

        self::assertTrue($nick->isNoExpire());
    }

    #[Test]
    public function setNoExpireFalseClearsFlag(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $nick->changeNoExpire(true);
        self::assertTrue($nick->isNoExpire());

        $nick->changeNoExpire(false);

        self::assertFalse($nick->isNoExpire());
    }

    #[Test]
    public function markPendingDeletionAndRestore(): void
    {
        $at = new DateTimeImmutable('2026-05-01 12:00:00');
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $nick->activate();

        $nick->markPendingDeletion($at);

        self::assertSame(NickStatus::PendingDeletion, $nick->getStatus());
        self::assertTrue($nick->isPendingDeletion());
        self::assertFalse($nick->isRegistered());
        self::assertSame($at, $nick->getPendingDeletionAt());
        self::assertSame('2026-05-08 12:00:00', $nick->getPendingDeletionExpiresAt(7)?->format('Y-m-d H:i:s'));
        self::assertSame($at, $nick->getPendingDeletionExpiresAt(0));

        $nick->restoreFromPendingDeletion();

        self::assertSame(NickStatus::Registered, $nick->getStatus());
        self::assertTrue($nick->isRegistered());
        self::assertNull($nick->getPendingDeletionAt());
    }

    #[Test]
    public function markPendingDeletionThrowsWhenNotRegistered(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only registered accounts can be marked for deletion.');

        $nick->markPendingDeletion(new DateTimeImmutable());
    }

    #[Test]
    public function restoreFromPendingDeletionThrowsWhenNotPendingDeletion(): void
    {
        $nick = RegisteredNick::createPending(
            'Nick',
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $nick->activate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only accounts pending deletion can be restored.');

        $nick->restoreFromPendingDeletion();
    }
}
