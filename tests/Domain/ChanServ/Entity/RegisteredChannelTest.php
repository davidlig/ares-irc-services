<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Entity;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\ValueObject\ChannelStatus;
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
        $channel = RegisteredChannel::register('#test', 1, 'A channel');

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
        self::assertInstanceOf(DateTimeImmutable::class, $channel->getCreatedAt());
    }

    #[Test]
    public function changeFounderAndAssignSuccessor(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->assignSuccessor(2);

        self::assertSame(2, $channel->getSuccessorNickId());

        $channel->changeFounder(2);

        self::assertSame(2, $channel->getFounderNickId());
        self::assertNull($channel->getSuccessorNickId());
    }

    #[Test]
    public function updateDescriptionUrlEmailAndEntrymsg(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

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
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entry message cannot exceed');

        $channel->updateEntrymsg(str_repeat('x', 256));
    }

    #[Test]
    public function configureTopicLockMlockAndSecure(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

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
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        $channel->updateTopic('Topic here', 'OpNick');
        self::assertSame('Topic here', $channel->getTopic());
        self::assertNotNull($channel->getLastTopicSetAt());
        self::assertSame('OpNick', $channel->getLastTopicSetByNick());

        $channel->updateTopic(null);
        self::assertNull($channel->getTopic());
        self::assertNull($channel->getLastTopicSetByNick());

        $channel->touchLastUsed();
        self::assertInstanceOf(DateTimeImmutable::class, $channel->getLastUsedAt());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $reflection = new ReflectionClass($channel);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($channel, 42);

        self::assertSame(42, $channel->getId());
    }

    #[Test]
    public function registerCreatesChannelWithActiveStatus(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertSame(ChannelStatus::Active, $channel->getStatus());
        self::assertFalse($channel->isSuspended());
    }

    #[Test]
    public function suspendSetsStatusToSuspended(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Policy violation');

        self::assertSame(ChannelStatus::Suspended, $channel->getStatus());
        self::assertSame('Policy violation', $channel->getSuspendedReason());
    }

    #[Test]
    public function suspendWithExpirationSetsUntil(): void
    {
        $until = new DateTimeImmutable('+7 days');
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Temporary suspension', $until);

        self::assertSame($until, $channel->getSuspendedUntil());
    }

    #[Test]
    public function suspendPermanentSetsUntilToNull(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Permanent suspension');

        self::assertNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function unsuspendResetsToActive(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Violation');
        $channel->unsuspend();

        self::assertSame(ChannelStatus::Active, $channel->getStatus());
    }

    #[Test]
    public function unsuspendClearsReasonAndUntil(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Violation', new DateTimeImmutable('+7 days'));
        $channel->unsuspend();

        self::assertNull($channel->getSuspendedReason());
        self::assertNull($channel->getSuspendedUntil());
    }

    #[Test]
    public function isSuspendedReturnsTrueWhenSuspended(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Violation');

        self::assertTrue($channel->isSuspended());
    }

    #[Test]
    public function isSuspendedReturnsFalseWhenActive(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertFalse($channel->isSuspended());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenPermanent(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Permanent');

        self::assertTrue($channel->isCurrentlySuspended());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsTrueWhenNotExpired(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Temporary', new DateTimeImmutable('+1 hour'));

        self::assertTrue($channel->isCurrentlySuspended());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenExpired(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Expired', new DateTimeImmutable('-1 second'));

        self::assertFalse($channel->isCurrentlySuspended());
    }

    #[Test]
    public function isCurrentlySuspendedReturnsFalseWhenActive(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertFalse($channel->isCurrentlySuspended());
    }

    #[Test]
    public function createForbiddenSetsForbiddenState(): void
    {
        $channel = RegisteredChannel::createForbidden('#forbidden', 'Spam channel');

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
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertFalse($channel->isForbidden());
    }

    #[Test]
    public function isForbiddenReturnsFalseWhenSuspended(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->suspend('Violation');

        self::assertFalse($channel->isForbidden());
    }

    #[Test]
    public function updateForbiddenReasonChangesReasonOnForbiddenChannel(): void
    {
        $channel = RegisteredChannel::createForbidden('#forbidden', 'Original reason');
        $channel->updateForbiddenReason('Updated reason');

        self::assertSame('Updated reason', $channel->getForbiddenReason());
    }

    #[Test]
    public function updateForbiddenReasonThrowsOnNonForbiddenChannel(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update forbidden reason on a non-forbidden channel.');

        $channel->updateForbiddenReason('reason');
    }

    #[Test]
    public function getForbiddenReasonReturnsNullWhenNotForbidden(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertNull($channel->getForbiddenReason());
    }

    #[Test]
    public function isNoExpireReturnsFalseByDefault(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');

        self::assertFalse($channel->isNoExpire());
    }

    #[Test]
    public function setNoExpireSetsValue(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channel->setNoExpire(true);

        self::assertTrue($channel->isNoExpire());

        $channel->setNoExpire(false);

        self::assertFalse($channel->isNoExpire());
    }
}
