<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Integration;

use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\InMemoryRegistrationThrottle;
use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;
use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRegisterNickRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRegistrationPolicyRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRescueCredentialPersistence;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickHistoryDoctrineRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandler;
use App\NickServ\Application\UseCase\Register\RegisterNickOutcome;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandler;
use App\NickServ\Application\UseCase\Resend\ResendVerificationOutcome;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandler;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function hash;
use function serialize;
use function str_repeat;

#[CoversNothing]
final class RegistrationReviewRegressionTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function capacityFailureBlocksSameIpOnlyAfterSqlPersistence(): void
    {
        $now = new DateTimeImmutable('2026-10-22T12:00:00Z');
        $db = $this->entityManager->getConnection();
        $policy = new DoctrineRegistrationPolicyRepository($db);
        $codec = new UuidRescueTokenCodec();
        $store = new InMemoryTemporaryRescueTokenStore(1);
        $store->replace(999, str_repeat('a', 64), $now->modify('+1 hour'));
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('hash');
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::never())->method('sendVerification');
        $register = new RegisterNickHandler($this->createStub(EmailDomainPolicy::class), new DoctrineRegisterNickRepository($this->entityManager), $hasher, $codec, $clock, $store, $policy, new InMemoryRegistrationThrottle(new RegisterThrottleRegistry()), $mail, 300);
        self::assertSame(RegisterNickOutcome::TemporarilyUnavailable, $register->handle(new RegisterNick('First', 'pw', null, 'en', 'ip:one'))->outcome);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks');
        self::assertIsInt($count);
        self::assertSame(1, $count);
        self::assertNull($db->fetchOne('SELECT token FROM registered_nicks'));
        $digest = static fn () => hash('sha256', serialize($db->fetchAllAssociative('SELECT * FROM registered_nicks ORDER BY id')));
        $before = $digest();
        $blocked = $register->handle(new RegisterNick('Second', 'pw', null, 'en', 'ip:one'));
        self::assertSame(RegisterNickOutcome::Throttled, $blocked->outcome);
        self::assertSame(300, $blocked->retryAfterSeconds);
        self::assertSame($before, $digest());
        self::assertSame(RegisterNickOutcome::TemporarilyUnavailable, $register->handle(new RegisterNick('OtherIp', 'pw', null, 'en', 'ip:two'))->outcome);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks');
        self::assertIsInt($count);
        self::assertSame(2, $count);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks WHERE token IS NOT NULL');
        self::assertIsInt($count);
        self::assertSame(0, $count);
    }

    #[Test]
    public function mailFailureAlsoBlocksSameIpWithoutPersistingSecret(): void
    {
        $now = new DateTimeImmutable('2026-10-22T12:00:00Z');
        $db = $this->entityManager->getConnection();
        $policy = new DoctrineRegistrationPolicyRepository($db);
        $policy->change(RegistrationMode::Email);
        $codec = new UuidRescueTokenCodec();
        $store = new InMemoryTemporaryRescueTokenStore();
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('hash');
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::exactly(2))->method('sendVerification')->willThrowException(new RuntimeException('synthetic mail failure'));
        $register = new RegisterNickHandler($this->createStub(EmailDomainPolicy::class), new DoctrineRegisterNickRepository($this->entityManager), $hasher, $codec, $clock, $store, $policy, new InMemoryRegistrationThrottle(new RegisterThrottleRegistry()), $mail, 300);
        self::assertSame(RegisterNickOutcome::MailDeliveryFailed, $register->handle(new RegisterNick('First', 'pw', 'first@example.test', 'en', 'ip:one'))->outcome);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks');
        self::assertIsInt($count);
        self::assertSame(1, $count);
        self::assertNull($db->fetchOne('SELECT token FROM registered_nicks'));
        $digest = static fn () => hash('sha256', serialize($db->fetchAllAssociative('SELECT * FROM registered_nicks ORDER BY id')));
        $before = $digest();
        $blocked = $register->handle(new RegisterNick('Second', 'pw', 'second@example.test', 'en', 'ip:one'));
        self::assertSame(RegisterNickOutcome::Throttled, $blocked->outcome);
        self::assertSame(300, $blocked->retryAfterSeconds);
        self::assertSame($before, $digest());
        self::assertSame(RegisterNickOutcome::MailDeliveryFailed, $register->handle(new RegisterNick('OtherIp', 'pw', 'other@example.test', 'en', 'ip:two'))->outcome);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks');
        self::assertIsInt($count);
        self::assertSame(2, $count);
        $count = $db->fetchOne('SELECT COUNT(*) FROM registered_nicks WHERE token IS NOT NULL');
        self::assertIsInt($count);
        self::assertSame(0, $count);
    }

    #[Test]
    public function resendRenewsSqlAndMemoryExpiryWithoutRevivingExpired(): void
    {
        $now = new DateTimeImmutable('2026-10-22T12:00:00Z');
        $originalDeadline = $now->modify('+1 second');
        $db = $this->entityManager->getConnection();
        $repo = new RegisteredNickDoctrineRepository($this->entityManager);
        $policy = new DoctrineRegistrationPolicyRepository($db);
        $policy->change(RegistrationMode::Email);
        $codec = new UuidRescueTokenCodec();
        $store = new InMemoryTemporaryRescueTokenStore();
        $nick = RegisteredNick::createPending('Renew', 'hash', 'renew@example.test', 'en', $originalDeadline, $now->modify('-3599 seconds'));
        $repo->save($nick);
        $old = $codec->generate();
        $oldHash = $codec->hash($old);
        self::assertIsString($oldHash);
        $store->replace($nick->getId(), $oldHash, $originalDeadline);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(static fn () => $now);
        $throttle = $this->createStub(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $sent = null;
        $mailer = $this->createMock(ResendMailSender::class);
        $mailer->expects(self::once())->method('sendResend')->willReturnCallback(static function (string $n, string $e, string $t) use (&$sent): void {$sent = $t; });
        $history = new NickHistoryService(new NickHistoryDoctrineRepository($this->entityManager));
        $resend = new ResendVerificationHandler($repo, $throttle, $codec, $store, $policy, $mailer, $clock, $history, 600, 7200);
        self::assertSame(ResendVerificationOutcome::Success, $resend->handle(new ResendVerification('Renew', 'en', 'Oper'))->outcome);
        self::assertIsString($sent);
        self::assertNotSame($old, $sent);
        self::assertSame($now->modify('+7200 seconds')->format('c'), $nick->getExpiresAt()?->format('c'));
        self::assertNull($nick->getRescueTokenHash());
        $this->entityManager->clear();
        $nick = $repo->findByNick('Renew');
        self::assertNotNull($nick);
        self::assertSame($now->modify('+7200 seconds')->format('c'), $nick->getExpiresAt()?->format('c'));
        self::assertFalse($store->matches($nick->getId(), $oldHash, $now));
        $later = $now->modify('+2 seconds');
        $laterClock = $this->createStub(Clock::class);
        $laterClock->method('now')->willReturn($later);
        $auth = $this->createStub(NicknameAuthenticationModeQuery::class);
        $auth->method('current')->willReturn(NicknameAuthenticationMode::ServiceCommand);
        $events = $this->createMock(RegistrationEventPublisher::class);
        $events->expects(self::once())->method('publish');
        $verify = new VerifyNickHandler($repo, $codec, $store, new InMemoryRescueAttemptLimiter(), new DoctrineRescueCredentialPersistence($this->entityManager), new IdentifiedSessionRegistry(), $laterClock, $auth, $events, $history);
        self::assertSame(VerifyNickOutcome::Success, $verify->handle(new VerifyNick('Renew', $sent, 'UID', 'Renew', clientKey: 'ip:one'))->outcome);
        $expired = RegisteredNick::createPending('Expired', 'hash', 'expired@example.test', 'en', $now, $now->modify('-1 hour'));
        $repo->save($expired);
        $digest = static fn () => hash('sha256', serialize($db->fetchAllAssociative('SELECT * FROM registered_nicks ORDER BY id')));
        $before = $digest();
        self::assertSame(ResendVerificationOutcome::NoPending, $resend->handle(new ResendVerification('Expired', 'en', 'Oper'))->outcome);
        self::assertSame($before, $digest());
        $policy->change(RegistrationMode::Token);
        self::assertSame(ResendVerificationOutcome::Disabled, $resend->handle(new ResendVerification('Expired', 'en', 'Oper'))->outcome);
        self::assertSame($before, $digest());
    }
}
