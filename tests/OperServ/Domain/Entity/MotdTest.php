<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Entity;

use App\OperServ\Domain\Entity\Motd;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(Motd::class)]
final class MotdTest extends TestCase
{
    #[Test]
    public function createSetsAllFields(): void
    {
        $before = new DateTimeImmutable();
        $expiry = new DateTimeImmutable('+1 day');
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG', 42, $expiry);
        $after = new DateTimeImmutable();

        self::assertSame('Welcome', $motd->getText());
        self::assertTrue($motd->isEnabled());
        self::assertSame('NickServ', $motd->getBotNickname());
        self::assertSame('PRIVMSG', $motd->getMessageType());
        self::assertSame(42, $motd->getCreatorNickId());
        self::assertSame($expiry, $motd->getExpiresAt());
        self::assertSame(0, $motd->getShownCount());
        self::assertGreaterThanOrEqual($before, $motd->getCreatedAt());
        self::assertLessThanOrEqual($after, $motd->getCreatedAt());
    }

    #[Test]
    public function recordShownIncrementsShownCount(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG');

        $motd->recordShown();
        $motd->recordShown();

        self::assertSame(2, $motd->getShownCount());
    }

    #[Test]
    public function createWithNullValues(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'NOTICE');

        self::assertNull($motd->getCreatorNickId());
        self::assertNull($motd->getExpiresAt());
    }

    #[Test]
    public function isExpiredReturnsFalseForNullExpiry(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG');

        self::assertFalse($motd->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG', null, new DateTimeImmutable('+1 hour'));

        self::assertFalse($motd->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG', null, new DateTimeImmutable('-1 hour'));

        self::assertTrue($motd->isExpired());
    }

    #[Test]
    public function clearCreatorNickIdSetsToNull(): void
    {
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG', 42);

        $motd->clearCreatorNickId();

        self::assertNull($motd->getCreatorNickId());
    }

    #[Test]
    public function isValidMessageTypeReturnsTrueForPrivmsg(): void
    {
        self::assertTrue(Motd::isValidMessageType('PRIVMSG'));
    }

    #[Test]
    public function isValidMessageTypeReturnsTrueForNotice(): void
    {
        self::assertTrue(Motd::isValidMessageType('NOTICE'));
    }

    #[Test]
    public function isValidMessageTypeReturnsFalseForUnknown(): void
    {
        self::assertFalse(Motd::isValidMessageType('BROADCAST'));
    }

    #[Test]
    public function constantsAreCorrect(): void
    {
        self::assertSame(400, strlen(str_repeat('x', Motd::MAX_TEXT_LENGTH)));
        self::assertSame('PRIVMSG', Motd::create('text', 'NickServ', Motd::TYPE_PRIVMSG)->getMessageType());
        self::assertSame('NOTICE', Motd::create('text', 'NickServ', Motd::TYPE_NOTICE)->getMessageType());
        self::assertSame(128, strlen(str_repeat('x', Motd::MAX_BOT_NICKNAME_LENGTH)));
    }

    #[Test]
    public function getCreatedAtReturnsDateTime(): void
    {
        $before = new DateTimeImmutable();
        $motd = Motd::create('Welcome', 'NickServ', 'PRIVMSG');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $motd->getCreatedAt());
        self::assertLessThanOrEqual($after, $motd->getCreatedAt());
    }
}
