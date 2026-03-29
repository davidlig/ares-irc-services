<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\ValueObject;

use App\Domain\OperServ\ValueObject\ForcedVhost;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForcedVhost::class)]
final class ForcedVhostTest extends TestCase
{
    #[Test]
    public function fromPatternCreatesValidVhost(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('admin.ares', $vhost->getPattern());
    }

    #[Test]
    public function fromPatternAcceptsValidPatterns(): void
    {
        $patterns = [
            'admin.ares',
            'staff.network',
            'a.b',
            'test-server.network',
            'x.y.z',
            'admin-1.network',
            'a-b.c-d',
        ];

        foreach ($patterns as $pattern) {
            $vhost = ForcedVhost::fromPattern($pattern);
            self::assertSame($pattern, $vhost->getPattern());
        }
    }

    #[Test]
    public function fromPatternTrimsWhitespace(): void
    {
        $vhost = ForcedVhost::fromPattern('  admin.ares  ');

        self::assertSame('admin.ares', $vhost->getPattern());
    }

    #[Test]
    public function fromPatternThrowsOnEmpty(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('VHost pattern cannot be empty.');

        ForcedVhost::fromPattern('');
    }

    #[Test]
    public function fromPatternThrowsOnWhitespaceOnly(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('VHost pattern cannot be empty.');

        ForcedVhost::fromPattern('   ');
    }

    #[Test]
    public function fromPatternThrowsOnTooLong(): void
    {
        $pattern = str_repeat('a', 49) . '.b';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('VHost pattern exceeds maximum length');

        ForcedVhost::fromPattern($pattern);
    }

    #[Test]
    public function fromPatternThrowsOnNoDot(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('admin');
    }

    #[Test]
    public function fromPatternThrowsOnStartingWithDot(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('.admin.ares');
    }

    #[Test]
    public function fromPatternThrowsOnEndingWithDot(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('admin.ares.');
    }

    #[Test]
    public function fromPatternThrowsOnStartingWithHyphen(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('-admin.ares');
    }

    #[Test]
    public function fromPatternThrowsOnEndingWithHyphen(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('admin-.ares');
    }

    #[Test]
    public function fromPatternThrowsOnConsecutiveDots(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid vhost pattern format');

        ForcedVhost::fromPattern('admin..ares');
    }

    #[Test]
    public function fromPatternAcceptsMaximumLength(): void
    {
        $pattern = 'a.' . str_repeat('b', 46);

        $vhost = ForcedVhost::fromPattern($pattern);
        self::assertSame($pattern, $vhost->getPattern());
    }

    #[Test]
    public function isValidPatternReturnsTrueForValidPattern(): void
    {
        self::assertTrue(ForcedVhost::isValidPattern('admin.ares'));
        self::assertTrue(ForcedVhost::isValidPattern('a.b'));
        self::assertTrue(ForcedVhost::isValidPattern('test-server.network'));
    }

    #[Test]
    public function isValidPatternReturnsFalseForInvalidPattern(): void
    {
        self::assertFalse(ForcedVhost::isValidPattern(null));
        self::assertFalse(ForcedVhost::isValidPattern(''));
        self::assertFalse(ForcedVhost::isValidPattern('   '));
        self::assertFalse(ForcedVhost::isValidPattern('.admin.ares'));
        self::assertFalse(ForcedVhost::isValidPattern('admin..ares'));
    }

    #[Test]
    public function isValidPatternReturnsFalseForPatternWithoutDot(): void
    {
        self::assertFalse(ForcedVhost::isValidPattern('admin'));
    }

    #[Test]
    public function generateVhostCombinesNicknameWithPattern(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('davidlig.admin.ares', $vhost->generateVhost('davidlig'));
    }

    #[Test]
    public function generateVhostCleansInvalidCharacters(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('davidlig.admin.ares', $vhost->generateVhost('_davidlig_'));
    }

    #[Test]
    public function generateVhostRemovesStartingHyphenAndDot(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('test.admin.ares', $vhost->generateVhost('-.test'));
    }

    #[Test]
    public function generateVhostRemovesEndingHyphenAndDot(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('test.admin.ares', $vhost->generateVhost('test.-'));
    }

    #[Test]
    public function generateVhostRemovesPipes(): void
    {
        $vhost = ForcedVhost::fromPattern('staff.net');

        self::assertSame('UserTest.staff.net', $vhost->generateVhost('User|Test'));
    }

    #[Test]
    public function generateVhostRemovesUnderscores(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('david.admin.ares', $vhost->generateVhost('_david_'));
    }

    #[Test]
    public function generateVhostKeepsDotsAndHyphens(): void
    {
        $vhost = ForcedVhost::fromPattern('staff.net');

        self::assertSame('test-user.site.staff.net', $vhost->generateVhost('test-user.site'));
    }

    #[Test]
    public function generateVhostReturnsUserWhenNicknameBecomesEmpty(): void
    {
        $vhost = ForcedVhost::fromPattern('admin.ares');

        self::assertSame('user.admin.ares', $vhost->generateVhost('|@#'));
    }

    #[Test]
    public function cleanNicknameRemovesInvalidCharacters(): void
    {
        self::assertSame('davidlig', ForcedVhost::cleanNickname('_davidlig_'));
        self::assertSame('UserTest', ForcedVhost::cleanNickname('User|Test'));
    }

    #[Test]
    public function cleanNicknameKeepsValidCharacters(): void
    {
        self::assertSame('davidlig', ForcedVhost::cleanNickname('davidlig'));
        self::assertSame('David-Lig.Site', ForcedVhost::cleanNickname('David-Lig.Site'));
        self::assertSame('test-123.abc', ForcedVhost::cleanNickname('test-123.abc'));
    }

    #[Test]
    public function cleanNicknameRemovesStartingDotAndHyphen(): void
    {
        self::assertSame('test', ForcedVhost::cleanNickname('.test'));
        self::assertSame('test', ForcedVhost::cleanNickname('-test'));
        self::assertSame('test', ForcedVhost::cleanNickname('.-test'));
    }

    #[Test]
    public function cleanNicknameRemovesEndingDotAndHyphen(): void
    {
        self::assertSame('test', ForcedVhost::cleanNickname('test.'));
        self::assertSame('test', ForcedVhost::cleanNickname('test-'));
        self::assertSame('test', ForcedVhost::cleanNickname('test.-'));
    }

    #[Test]
    public function cleanNicknameRemovesConsecutiveDotsAndHyphens(): void
    {
        self::assertSame('test.site', ForcedVhost::cleanNickname('test..site'));
        self::assertSame('test-site', ForcedVhost::cleanNickname('test--site'));
    }

    #[Test]
    public function cleanNicknameReturnsUserForEmptyResult(): void
    {
        self::assertSame('user', ForcedVhost::cleanNickname('|@#$'));
        self::assertSame('user', ForcedVhost::cleanNickname(''));
    }

    #[Test]
    public function cleanNicknameReturnsUserWhenOnlyInvalidCharsAfterTrim(): void
    {
        self::assertSame('user', ForcedVhost::cleanNickname('--'));
        self::assertSame('user', ForcedVhost::cleanNickname('..'));
        self::assertSame('user', ForcedVhost::cleanNickname('-.'));
        self::assertSame('user', ForcedVhost::cleanNickname('.-'));
        self::assertSame('user', ForcedVhost::cleanNickname('...'));
        self::assertSame('user', ForcedVhost::cleanNickname('---'));
        self::assertSame('user', ForcedVhost::cleanNickname('-.-'));
        self::assertSame('user', ForcedVhost::cleanNickname('.-.--...'));
    }

    #[Test]
    public function cleanNicknameHandlesTrickyEdgeCases(): void
    {
        self::assertSame('test.site', ForcedVhost::cleanNickname('-test.site-'));
        self::assertSame('a.b', ForcedVhost::cleanNickname('.a.b.'));
        self::assertSame('x-y', ForcedVhost::cleanNickname('-x-y-'));
    }

    #[Test]
    public function fromPatternWithVeryLongPatternThrows(): void
    {
        $pattern = str_repeat('a', 50);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('VHost pattern exceeds maximum length');

        ForcedVhost::fromPattern($pattern);
    }

    #[Test]
    public function fromPatternWithWhitespacePatternThrows(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('VHost pattern cannot be empty.');

        ForcedVhost::fromPattern('   ');
    }

    #[Test]
    public function isValidPatternWithVeryLongPatternReturnsFalse(): void
    {
        $pattern = str_repeat('a', 50);

        self::assertFalse(ForcedVhost::isValidPattern($pattern));
    }
}
