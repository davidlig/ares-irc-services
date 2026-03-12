<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Entity;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('100', $channel->getMlockParam('l'));

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
}
