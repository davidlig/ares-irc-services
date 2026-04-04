<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Entity;

use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegisteredNick::class)]
final class RegisteredNickForbiddenTest extends TestCase
{
    #[Test]
    public function updateForbiddenReasonUpdatesReasonOnForbiddenNick(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Original reason');

        $nick->updateForbiddenReason('New reason');

        self::assertSame('New reason', $nick->getReason());
    }

    #[Test]
    public function updateForbiddenReasonThrowsExceptionOnNonForbiddenNick(): void
    {
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update forbidden reason on a non-forbidden account.');

        $nick->updateForbiddenReason('New reason');
    }

    #[Test]
    public function updateForbiddenReasonThrowsExceptionOnSuspendedNick(): void
    {
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();
        $nick->suspend('Bad behavior', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot update forbidden reason on a non-forbidden account.');

        $nick->updateForbiddenReason('New reason');
    }

    #[Test]
    public function isForbiddenReturnsTrueForForbiddenNick(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        self::assertTrue($nick->isForbidden());
    }

    #[Test]
    public function isForbiddenReturnsFalseForRegisteredNick(): void
    {
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        self::assertFalse($nick->isForbidden());
    }

    #[Test]
    public function isForbiddenReturnsFalseForSuspendedNick(): void
    {
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();
        $nick->suspend('Bad behavior', null);

        self::assertFalse($nick->isForbidden());
    }
}
