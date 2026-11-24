<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use App\ChanServ\Domain\Policy\PreparedAkickRules;
use App\ChanServ\Domain\ValueObject\AkickAdditionDecision;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(AkickProtectionPolicy::class)]
#[CoversClass(PreparedAkickRules::class)]
#[CoversClass(AkickAdditionPolicy::class)]
#[CoversClass(AkickAdditionDecision::class)]
#[CoversClass(AkickMask::class)]
#[CoversClass(AkickRule::class)]
final class AkickPolicyTest extends TestCase
{
    #[Test]
    public function matchingIsCaseInsensitiveAndFirstNonExpiredRuleWins(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $expired = new AkickRule(new AkickMask('Bad*!*@*'), expiresAt: $now->modify('-1 second'));
        $first = new AkickRule(new AkickMask('bad*!*@*'), 'first');
        $later = new AkickRule(new AkickMask('*!*@*'), 'later');

        self::assertSame($first, PreparedAkickRules::prepare([$expired, $first, $later], NegotiatedCaseMapping::fold())->firstMatch('BADNick!ident@host', $now, false));
    }

    #[Test]
    public function matchingTreatsBracketsAndBackslashesAsLiteralWhilePreservingWildcards(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $brackets = new AkickRule(new AkickMask('[Foo]!*@*'));
        $backslash = new AkickRule(new AkickMask('a\\b!*@*'));
        $wildcard = new AkickRule(new AkickMask('Bad?!*@*.example'));

        self::assertSame($brackets, PreparedAkickRules::prepare([$brackets], NegotiatedCaseMapping::fold())->firstMatch('[foo]!u@h', $now, false));
        self::assertNull(PreparedAkickRules::prepare([$brackets], NegotiatedCaseMapping::fold())->firstMatch('f!u@h', $now, false));
        self::assertSame($backslash, PreparedAkickRules::prepare([$backslash], NegotiatedCaseMapping::fold())->firstMatch('A\\B!u@h', $now, false));
        self::assertSame([$brackets], PreparedAkickRules::prepare([$brackets], NegotiatedCaseMapping::fold())->allMatches('[Foo]!u@h', $now, false));
        self::assertSame($wildcard, PreparedAkickRules::prepare([$wildcard], NegotiatedCaseMapping::fold())->firstMatch('bad1!u@host.example', $now, false));
    }

    #[Test]
    public function repeatedWildcardsDoNotExhaustTheMatcher(): void
    {
        $mask = new AkickMask(str_repeat('*a', 20) . '!*@*');

        self::assertTrue($mask->matches(str_repeat('a', 25) . '!u@host', NegotiatedCaseMapping::fold()));
        self::assertFalse($mask->matches(str_repeat('a', 19) . '!u@host', NegotiatedCaseMapping::fold()));
        self::assertTrue(new AkickMask('nick!u@*')->matches('nick!u@', NegotiatedCaseMapping::fold()));
        self::assertTrue(new AkickMask('nick!u@*')->matches('nick!u@*suffix', NegotiatedCaseMapping::fold()));
    }

    #[Test]
    public function expirationAtExactInstantIsStillValidAndOperatorsAreExempt(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $rule = new AkickRule(new AkickMask('*!*@host'), expiresAt: $now);

        self::assertSame($rule, PreparedAkickRules::prepare([$rule], NegotiatedCaseMapping::fold())->firstMatch('nick!ident@host', $now, false));
        self::assertNull(PreparedAkickRules::prepare([$rule], NegotiatedCaseMapping::fold())->firstMatch('nick!ident@host', $now, true));
        self::assertNull(PreparedAkickRules::prepare([$rule], NegotiatedCaseMapping::fold())->firstMatch('nick!ident@elsewhere', $now, false));
    }

    #[Test]
    public function allMatchesReturnsAllActiveMatchingRules(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $rule1 = new AkickRule(new AkickMask('bad*!*@*'), 'first');
        $rule2 = new AkickRule(new AkickMask('*!*@host'), 'second');
        $expired = new AkickRule(new AkickMask('bad*!*@*'), 'expired', expiresAt: $now->modify('-1 second'));
        $unrelated = new AkickRule(new AkickMask('other!*@*'), 'unrelated');

        $matches = PreparedAkickRules::prepare([$rule1, $expired, $rule2, $unrelated], NegotiatedCaseMapping::fold())->allMatches('badnick!ident@host', $now, false);
        self::assertSame([$rule1, $rule2], $matches);

        // Operator is exempt
        self::assertSame([], PreparedAkickRules::prepare([$rule1, $rule2], NegotiatedCaseMapping::fold())->allMatches('badnick!ident@host', $now, true));
    }

    #[Test]
    public function preparedRulesFoldEachPatternOnceAndMatchLikeTheMaskItself(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $calls = [];
        $fold = static function (string $value) use (&$calls): string {
            $calls[] = $value;

            return NegotiatedCaseMapping::fold()($value);
        };
        $rules = [new AkickRule(new AkickMask('[Bad]*!*@*')), new AkickRule(new AkickMask('*!*@Host.Example'))];
        $prepared = PreparedAkickRules::prepare($rules, $fold);
        $masks = ['{bad}x!u@h', 'Good!u@HOST.example', 'good!u@elsewhere'];
        $expected = [];
        foreach ($masks as $mask) {
            $expected[] = $rules[0]->mask->matches($mask, NegotiatedCaseMapping::fold()) || $rules[1]->mask->matches($mask, NegotiatedCaseMapping::fold());
        }

        $actual = [];
        foreach ($masks as $mask) {
            $actual[] = null !== $prepared->firstMatch($mask, $now, false);
        }

        self::assertSame([true, true, false], $expected);
        self::assertSame($expected, $actual);
        self::assertSame(['[Bad]*!*@*', '*!*@Host.Example', ...$masks], $calls);
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

        self::assertSame('AccessNick', $policy->firstProtectedNickname(new AkickMask('access*!*@*'), $protected, NegotiatedCaseMapping::fold()));
        self::assertNull($policy->firstProtectedNickname(new AkickMask('ordinary*!*@*'), $protected, NegotiatedCaseMapping::fold()));
    }

    #[Test]
    public function wildcardOnlyNicknamePartRemainsAllowedForHostBan(): void
    {
        self::assertNull(new AkickProtectionPolicy()->firstProtectedNickname(
            new AkickMask('**!*@*.example.org'),
            ['Founder'],
            NegotiatedCaseMapping::fold(),
        ));
    }

    #[Test]
    public function maskRequiresFullUserHostShapeAndPreservesNicknamePattern(): void
    {
        self::assertSame('Nick*', new AkickMask('Nick*!ident@host')->nicknamePattern());

        try {
            new AkickMask('nick@host');
            self::fail('Expected missing ident separator to fail.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
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
        $this->expectException(\InvalidArgumentException::class);
        new AkickRule(new AkickMask('*!*@safe.example'), str_repeat('r', AkickRule::MAX_REASON_LENGTH + 1));
    }

    #[Test]
    public function additionRejectsActiveDuplicatesAndLimitButReplacesExpiredDuplicate(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
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
        $this->expectException(\InvalidArgumentException::class);
        new AkickAdditionPolicy()->decide(-1, null, new \DateTimeImmutable());
    }

    #[Test]
    public function matchingFoldsNicknamesWithTheRfc1459Mapping(): void
    {
        $now = new \DateTimeImmutable('2026-09-07 12:00:00');
        $rule = new AkickRule(new AkickMask('[bad]*!*@*'));
        $braces = new AkickRule(new AkickMask('{bad}\\^!*@*'));
        $ascii = new AkickRule(new AkickMask('Bad*!*@*'));

        self::assertSame($rule, PreparedAkickRules::prepare([$rule], NegotiatedCaseMapping::fold())->firstMatch('{BAD}x!u@h', $now, false));
        self::assertSame($braces, PreparedAkickRules::prepare([$braces], NegotiatedCaseMapping::fold())->firstMatch('[BAD]|~!u@h', $now, false));
        self::assertSame($ascii, PreparedAkickRules::prepare([$ascii], NegotiatedCaseMapping::fold())->firstMatch('bAdNick!u@h', $now, false));
        self::assertNull(PreparedAkickRules::prepare([$ascii], NegotiatedCaseMapping::fold())->firstMatch('Good!u@h', $now, false));
    }

    #[Test]
    public function protectionFoldsNicknamesWithTheRfc1459Mapping(): void
    {
        $policy = new AkickProtectionPolicy();

        self::assertSame('{Founder}', $policy->firstProtectedNickname(new AkickMask('[founder]*!*@*'), ['{Founder}'], NegotiatedCaseMapping::fold()));
        self::assertSame('Founder', $policy->firstProtectedNickname(new AkickMask('FOUNDER!*@*'), ['Founder'], NegotiatedCaseMapping::fold()));
        self::assertNull($policy->firstProtectedNickname(new AkickMask('(founder)!*@*'), ['{Founder}'], NegotiatedCaseMapping::fold()));
    }

    #[Test]
    public function asciiNetworksKeepBracketsAndBracesApartSoNoKickLoopsAgainstTheIrcdBan(): void
    {
        $now = new \DateTimeImmutable('2026-11-15 12:00:00');
        $rule = new AkickRule(new AkickMask('[bot]*!*@*'));
        $ascii = NegotiatedCaseMapping::fold(CaseMapping::Ascii);

        self::assertNull(PreparedAkickRules::prepare([$rule], $ascii)->firstMatch('{bot}x!u@h', $now, false));
        self::assertSame($rule, PreparedAkickRules::prepare([$rule], $ascii)->firstMatch('[BOT]x!u@h', $now, false));
        self::assertNull(PreparedAkickRules::prepare([$rule], $ascii)->firstMatch('bx!u@h', $now, false));
        self::assertSame([], PreparedAkickRules::prepare([$rule], $ascii)->allMatches('{bot}x!u@h', $now, false));
        self::assertNull(new AkickProtectionPolicy()->firstProtectedNickname(new AkickMask('[founder]*!*@*'), ['{Founder}'], $ascii));
        self::assertSame('[Founder]', new AkickProtectionPolicy()->firstProtectedNickname(new AkickMask('[founder]*!*@*'), ['[Founder]'], $ascii));
    }
}
