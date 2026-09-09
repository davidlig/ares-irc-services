<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Entity;

use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RegisteredChannel::class)]
final class RegisteredChannelTest extends TestCase
{
    #[Test]
    public function registerCreatesChannelWithInitialState(): void
    {
        $before = new DateTimeImmutable();
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'A channel');
        $after = new DateTimeImmutable();

        self::assertSame('#test', $channel->getName());
        self::assertSame('#test', $channel->getNameLower());
        self::assertSame(1, $channel->getFounderNickId());
        self::assertNull($channel->getSuccessorNickId());
        self::assertSame('A channel', $channel->getDescription());
        self::assertSame('', $channel->getEntrymsg());
        self::assertFalse($channel->isTopicLock());
        self::assertFalse($channel->isMlockActive());
        self::assertSame('', $channel->getMlock());
        self::assertFalse($channel->isSecure());
        self::assertNull($channel->getTopic());
        self::assertTrue($channel->isFounder(1));
        self::assertFalse($channel->isFounder(2));
        self::assertGreaterThanOrEqual($before, $channel->getCreatedAt());
        self::assertLessThanOrEqual($after, $channel->getCreatedAt());
    }

    #[Test]
    public function changeFounderAndAssignSuccessor(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->assignSuccessor(2);

        self::assertSame(2, $channel->getSuccessorNickId());

        $channel->changeFounder(2);

        self::assertSame(2, $channel->getFounderNickId());
        self::assertNull($channel->getSuccessorNickId());
    }

    #[Test]
    public function updateDescriptionUrlEmailAndEntrymsg(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $channel->updateDescription('New desc');
        self::assertSame('New desc', $channel->getDescription());

        $channel->updateUrl('https://example.com');
        self::assertSame('https://example.com', $channel->getUrl());

        $channel->updateEmail('chan@example.com');
        self::assertSame('chan@example.com', $channel->getEmail());

        $channel->updateEntrymsg('Welcome');
        self::assertSame('Welcome', $channel->getEntrymsg());
    }

    #[Test]
    public function updateEntrymsgThrowsWhenTooLong(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry message cannot exceed');

        $channel->updateEntrymsg(str_repeat('x', 256));
    }

    #[Test]
    public function configureTopicLockMlockAndSecure(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $channel->configureTopicLock(true);
        self::assertTrue($channel->isTopicLock());

        $channel->configureMlock(true, '+nt', ['l' => '100']);
        self::assertTrue($channel->isMlockActive());
        self::assertSame('+nt', $channel->getMlock());
        self::assertSame(['l' => '100'], $channel->getMlockParams());
        self::assertSame('100', $channel->getMlockParam('l'));
        self::assertNull($channel->getMlockParam('k'));

        $channel->configureMlock(true, '', []);
        self::assertSame('', $channel->getMlock());
        self::assertSame([], $channel->getMlockParams());

        $channel->configureSecure(true);
        self::assertTrue($channel->isSecure());
    }

    #[Test]
    public function updateTopicAndTouchLastUsed(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $channel->updateTopic('Topic here', new DateTimeImmutable('2026-01-01 00:00:00'), 'OpNick');
        self::assertSame('Topic here', $channel->getTopic());
        self::assertNotNull($channel->getLastTopicSetAt());
        self::assertSame('OpNick', $channel->getLastTopicSetByNick());

        $channel->updateTopic(null, new DateTimeImmutable('2026-01-01 00:00:00'));
        self::assertNull($channel->getTopic());
        self::assertNull($channel->getLastTopicSetByNick());

        $lastUsedAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $channel->touchLastUsed($lastUsedAt);
        self::assertSame($lastUsedAt, $channel->getLastUsedAt());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $reflection = new ReflectionClass($channel);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($channel, 42);

        self::assertSame(42, $channel->getId());
    }

    #[Test]
    public function registerCreatesChannelWithActiveStatus(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertSame(ChannelStatus::Active, $channel->getStatus());
        self::assertFalse($channel->isSuspended());
    }

    #[Test]
    public function suspendSetsStatusToSuspended(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Policy violation');

        self::assertSame(ChannelStatus::Suspended, $channel->getStatus());
        self::assertSame('Policy violation', $channel->getSuspendedReason());
    }

    #[Test]
    public function suspendWithExpirationSetsUntil(): void
    {
        $until = new DateTimeImmutable('+7 days');
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Temporary suspension', $until);

        self::assertSame($until, $channel->getSuspendedUntil());
    }

    #[Test]
    public function suspendPermanentSetsUntilToNull(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Permanent suspension');

        self::assertNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function unsuspendResetsToActive(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Violation');
        $channel->unsuspend();

        self::assertSame(ChannelStatus::Active, $channel->getStatus());
    }

    #[Test]
    public function unsuspendClearsReasonAndUntil(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Violation', new DateTimeImmutable('+7 days'));
        $channel->unsuspend();

        self::assertNull($channel->getSuspendedReason());
        self::assertNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function isSuspendedReturnsTrueWhenSuspended(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Violation');

        self::assertTrue($channel->isSuspended());
    }

    #[Test]
    public function isSuspendedReturnsFalseWhenActive(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertFalse($channel->isSuspended());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenPermanent(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Permanent');

        self::assertTrue($channel->isCurrentlySuspended(new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenNotExpired(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Temporary', new DateTimeImmutable('2026-01-02 04:00:00'));

        self::assertTrue($channel->isCurrentlySuspended(new DateTimeImmutable('2026-01-02 03:00:00')));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenExpired(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Expired', new DateTimeImmutable('2026-01-02 02:59:59'));

        self::assertFalse($channel->isCurrentlySuspended(new DateTimeImmutable('2026-01-02 03:00:00')));
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenActive(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertFalse($channel->isCurrentlySuspended(new DateTimeImmutable('2026-01-02 03:04:05')));
    }

    #[Test]
    public function createForbiddenSetsForbiddenState(): void
    {
        $channel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden', 'Spam channel');

        self::assertSame('#forbidden', $channel->getName());
        self::assertSame('#forbidden', $channel->getNameLower());
        self::assertSame(ChannelStatus::Forbidden, $channel->getStatus());
        self::assertTrue($channel->isForbidden());
        self::assertSame('Spam channel', $channel->getForbiddenReason());
        self::assertSame(0, $channel->getFounderNickId());
        self::assertSame('', $channel->getDescription());
    }

    #[Test]
    public function isForbiddenReturnsFalseWhenActive(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertFalse($channel->isForbidden());
    }

    #[Test]
    public function isForbiddenReturnsFalseWhenSuspended(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Violation');

        self::assertFalse($channel->isForbidden());
    }

    #[Test]
    public function updateForbiddenReasonChangesReasonOnForbiddenChannel(): void
    {
        $channel = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden', 'Original reason');
        $channel->updateForbiddenReason('Updated reason');

        self::assertSame('Updated reason', $channel->getForbiddenReason());
    }

    #[Test]
    public function updateForbiddenReasonThrowsOnNonForbiddenChannel(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update forbidden reason on a non-forbidden channel.');

        $channel->updateForbiddenReason('reason');
    }

    #[Test]
    public function getForbiddenReasonReturnsNullWhenNotForbidden(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertNull($channel->getForbiddenReason());
    }

    #[Test]
    public function isNoExpireReturnsFalseByDefault(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertFalse($channel->isNoExpire());
    }

    #[Test]
    public function setNoExpireSetsValue(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->changeNoExpire(true);

        self::assertTrue($channel->isNoExpire());

        $channel->changeNoExpire(false);

        self::assertFalse($channel->isNoExpire());
    }

    #[Test]
    public function markPendingDeletionAndRestore(): void
    {
        $at = new DateTimeImmutable('2026-05-01 12:00:00');
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $channel->markPendingDeletion($at);

        self::assertSame(ChannelStatus::PendingDeletion, $channel->getStatus());
        self::assertTrue($channel->isPendingDeletion());
        self::assertSame($at, $channel->getPendingDeletionAt());
        self::assertSame('2026-05-08 12:00:00', $channel->getPendingDeletionExpiresAt(7)?->format('Y-m-d H:i:s'));
        self::assertSame($at, $channel->getPendingDeletionExpiresAt(0));

        $channel->restoreFromPendingDeletion();

        self::assertSame(ChannelStatus::Active, $channel->getStatus());
        self::assertNull($channel->getPendingDeletionAt());
    }

    #[Test]
    public function markPendingDeletionThrowsWhenNotActive(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');
        $channel->suspend('Reason');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only active channels can be marked for deletion.');

        $channel->markPendingDeletion(new DateTimeImmutable('2026-01-02 03:04:05'));
    }

    #[Test]
    public function restoreFromPendingDeletionThrowsWhenNotPendingDeletion(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only channels pending deletion can be restored.');

        $channel->restoreFromPendingDeletion();
    }

    #[Test]
    public function isBlockedReturnsTrueForBlockedStates(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Desc');

        self::assertFalse($channel->isBlocked());

        $channel->suspend('Reason');
        self::assertTrue($channel->isBlocked());

        $channel->unsuspend();
        self::assertFalse($channel->isBlocked());

        $forbidden = RegisteredChannel::createForbidden(new DateTimeImmutable(), '#forbidden', 'Reason');
        self::assertTrue($forbidden->isBlocked());

        $channel->markPendingDeletion(new DateTimeImmutable('2026-01-02 03:04:05'));
        self::assertTrue($channel->isBlocked());

        $channel->restoreFromPendingDeletion();
        self::assertFalse($channel->isBlocked());
    }
}
