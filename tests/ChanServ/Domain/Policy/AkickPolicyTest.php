<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use App\ChanServ\Domain\ValueObject\AkickAdditionDecision;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AkickMatchPolicy::class)]
#[CoversClass(AkickProtectionPolicy::class)]
#[CoversClass(AkickAdditionPolicy::class)]
#[CoversClass(AkickAdditionDecision::class)]
#[CoversClass(AkickMask::class)]
#[CoversClass(AkickRule::class)]
final class AkickPolicyTest extends TestCase
{
    #[Test]
    public function matchingIsCaseInsensitiveAndFirstNonExpiredRuleWins(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00');
        $expired = new AkickRule(new AkickMask('Bad*!*@*'), expiresAt: $now->modify('-1 second'));
        $first = new AkickRule(new AkickMask('bad*!*@*'), 'first');
        $later = new AkickRule(new AkickMask('*!*@*'), 'later');

        self::assertSame($first, new AkickMatchPolicy()->firstMatch([$expired, $first, $later], 'BADNick!ident@host', $now, false));
    }

    #[Test]
    public function expirationAtExactInstantIsStillValidAndOperatorsAreExempt(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00');
        $rule = new AkickRule(new AkickMask('*!*@host'), expiresAt: $now);
        $policy = new AkickMatchPolicy();

        self::assertSame($rule, $policy->firstMatch([$rule], 'nick!ident@host', $now, false));
        self::assertNull($policy->firstMatch([$rule], 'nick!ident@host', $now, true));
        self::assertNull($policy->firstMatch([$rule], 'nick!ident@elsewhere', $now, false));
    }

    #[Test]
    public function missingReasonGetsStableFallback(): void
    {
        self::assertSame('AKICK: *!*@bad.host', new AkickRule(new AkickMask('*!*@bad.host'))->enforcementReason());
        $emptyReason = new AkickRule(new AkickMask('*!*@bad.host'), '');
        self::assertNull($emptyReason->reason);
        self::assertSame('AKICK: *!*@bad.host', $emptyReason->enforcementReason());
        self::assertSame('custom', new AkickRule(new AkickMask('*!*@bad.host'), 'custom')->enforcementReason());
    }

    #[Test]
    public function founderSuccessorAndAccessNicksCanBeProtectedBySamePolicy(): void
    {
        $protected = ['Founder', 'Successor', 'AccessNick'];
        $policy = new AkickProtectionPolicy();

        self::assertSame('AccessNick', $policy->firstProtectedNickname(new AkickMask('access*!*@*'), $protected));
        self::assertNull($policy->firstProtectedNickname(new AkickMask('ordinary*!*@*'), $protected));
    }

    #[Test]
    public function wildcardOnlyNicknamePartRemainsAllowedForHostBan(): void
    {
        self::assertNull(new AkickProtectionPolicy()->firstProtectedNickname(
            new AkickMask('**!*@*.example.org'),
            ['Founder'],
        ));
    }

    #[Test]
    public function maskRequiresFullUserHostShapeAndPreservesNicknamePattern(): void
    {
        self::assertSame('Nick*', new AkickMask('Nick*!ident@host')->nicknamePattern());

        try {
            new AkickMask('nick@host');
            self::fail('Expected missing ident separator to fail.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new AkickMask(str_repeat('a', AkickMask::MAX_LENGTH) . '!*@host');
    }

    #[Test]
    public function maskSafetyRequiresAConcreteNickOrFourAlphanumericIdentityHostCharacters(): void
    {
        self::assertTrue(new AkickMask('Bad*!*@*')->isSafe());
        self::assertTrue(new AkickMask('*!*@a1b2')->isSafe());
        self::assertFalse(new AkickMask('*!*@a1b')->isSafe());
        self::assertFalse(new AkickMask('??!*@*')->isSafe());
    }

    #[Test]
    public function reasonCannotExceedTheLegacyLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AkickRule(new AkickMask('*!*@safe.example'), str_repeat('r', AkickRule::MAX_REASON_LENGTH + 1));
    }

    #[Test]
    public function additionRejectsActiveDuplicatesAndLimitButReplacesExpiredDuplicate(): void
    {
        $now = new DateTimeImmutable('2026-09-07 12:00:00');
        $policy = new AkickAdditionPolicy();

        self::assertSame(AkickAdditionDecision::Add, $policy->decide(99, null, $now));
        self::assertSame(AkickAdditionDecision::LimitReached, $policy->decide(100, null, $now));
        self::assertSame(
            AkickAdditionDecision::DuplicateActive,
            $policy->decide(100, new AkickRule(new AkickMask('*!*@safe.example'), expiresAt: $now), $now),
        );
        self::assertSame(
            AkickAdditionDecision::ReplaceExpired,
            $policy->decide(100, new AkickRule(new AkickMask('*!*@safe.example'), expiresAt: $now->modify('-1 second')), $now),
        );
    }

    #[Test]
    public function additionRejectsNegativeEntryCounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AkickAdditionPolicy()->decide(-1, null, new DateTimeImmutable());
    }
}
