<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\ValueObject\AccessLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelAccessPolicy::class)]
#[CoversClass(AccessLevel::class)]
#[CoversClass(ChannelLevel::class)]
#[CoversClass(ChannelLevelSet::class)]
final class ChannelAccessPolicyTest extends TestCase
{
    private ChannelAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ChannelAccessPolicy();
    }

    #[Test]
    public function unidentifiedUserAlwaysHasMinusOneEvenWhenFounderOrStored(): void
    {
        self::assertSame(-1, $this->policy->effectiveLevel(false, true, 499)->value);
    }

    #[Test]
    public function identifiedFounderHasImplicitFiveHundred(): void
    {
        self::assertSame(500, $this->policy->effectiveLevel(true, true, null)->value);
    }

    #[Test]
    public function identifiedNonFounderUsesStoredLevelOrZero(): void
    {
        self::assertSame(230, $this->policy->effectiveLevel(true, false, 230)->value);
        self::assertSame(0, $this->policy->effectiveLevel(true, false, null)->value);
    }

    #[Test]
    public function managerCanOnlyManageStrictlyLowerStoredLevel(): void
    {
        $manager = AccessLevel::fromEffective(300);

        self::assertTrue($manager->meets(300));
        self::assertFalse($manager->meets(301));
        self::assertTrue($this->policy->canManageLevel($manager, 299));
        self::assertTrue($this->policy->canManageLevel($manager, 0));
        self::assertFalse($this->policy->canManageLevel($manager, 300));
    }

    #[Test]
    public function accessListIsLimitedToOneHundredEntries(): void
    {
        self::assertTrue($this->policy->canAddEntry(99));
        self::assertFalse($this->policy->canAddEntry(100));
        self::assertFalse($this->policy->canAddEntry(-1));
    }

    #[Test]
    public function accessLevelsRejectValuesOutsideTheirSemanticRanges(): void
    {
        try {
            AccessLevel::fromEffective(501);
            self::fail('Expected invalid effective access.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        AccessLevel::fromStored(0);
    }

    #[Test]
    public function levelDefaultsAndOverridesAreExplicitAndResettable(): void
    {
        $expected = [
            'AUTOADMIN' => 400, 'AUTOOP' => 300, 'AUTOHALFOP' => 200, 'AUTOVOICE' => 100,
            'SET' => 499, 'ADMINDEADMIN' => 400, 'OPDEOP' => 300, 'HALFOPDEHALFOP' => 200,
            'VOICEDEVOICE' => 100, 'INVITE' => 200, 'ACCESSLIST' => 400, 'ACCESSCHANGE' => 499,
            'MEMOREAD' => 200, 'MEMOCHANGE' => 300, 'AKICK' => 450, 'NOJOIN' => -1,
        ];
        $levels = ChannelLevelSet::defaults();

        foreach (ChannelLevel::cases() as $level) {
            self::assertSame($expected[$level->value], $levels->valueFor($level));
        }

        $overridden = $levels->withOverride(ChannelLevel::AutoOperator, -1);
        self::assertSame(-1, $overridden->valueFor(ChannelLevel::AutoOperator));
        self::assertSame(['AUTOOP' => -1], $overridden->overrides());
        self::assertSame(300, $overridden->withoutOverride(ChannelLevel::AutoOperator)->valueFor(ChannelLevel::AutoOperator));
    }

    #[Test]
    public function levelSetValidatesKeysAndRange(): void
    {
        self::assertSame(250, ChannelLevelSet::fromOverrides(['AUTOOP' => 250])->valueFor(ChannelLevel::AutoOperator));

        try {
            ChannelLevelSet::fromOverrides(['UNKNOWN' => 1]);
            self::fail('Expected unknown level failure.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        ChannelLevelSet::defaults()->withOverride(ChannelLevel::NoJoin, 500);
    }
}
