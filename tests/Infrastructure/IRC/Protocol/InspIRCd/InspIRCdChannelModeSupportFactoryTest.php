<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdCapab;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdChannelModeSupportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdChannelModeSupportFactory::class)]
final class InspIRCdChannelModeSupportFactoryTest extends TestCase
{
    private InspIRCdChannelModeSupportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new InspIRCdChannelModeSupportFactory();
    }

    #[Test]
    public function createDefaultReturnsFullInspircdProfile(): void
    {
        $support = $this->factory->createDefault();

        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertTrue($support->hasPermanentChannelMode());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertSame('P', $support->getPermanentChannelModeLetter());
        self::assertSame('r', $support->getChannelRegisteredModeLetter());
        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function createDefaultIncludesAllListModes(): void
    {
        $support = $this->factory->createDefault();
        $list = $support->getListModeLetters();

        self::assertContains('b', $list);
        self::assertContains('e', $list);
        self::assertContains('I', $list);
        self::assertContains('g', $list);
    }

    #[Test]
    public function createDefaultIncludesChannelSettingModes(): void
    {
        $support = $this->factory->createDefault();

        self::assertContains('n', $support->getChannelSettingModesUnsetWithoutParam());
        self::assertContains('t', $support->getChannelSettingModesUnsetWithoutParam());
        self::assertContains('P', $support->getChannelSettingModesUnsetWithoutParam());
        self::assertSame(['k'], $support->getChannelSettingModesUnsetWithParam());
        self::assertContains('k', $support->getChannelSettingModesWithParamOnSet());
        self::assertContains('l', $support->getChannelSettingModesWithParamOnSet());
    }

    #[Test]
    public function createFromCapabWithFullRanks(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:banexception=e list:invex=I param-set:key=k param-set:limit=l prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q simple:inviteonly=i simple:c_registered=r simple:topiclock=t simple:private=p simple:P=P',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function createFromCapabWithoutExtendedRanks(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:banexception=e list:invex=I param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:c_registered=r simple:topiclock=t',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertTrue($support->hasVoice());
        self::assertFalse($support->hasHalfOp());
        self::assertTrue($support->hasOp());
        self::assertFalse($support->hasAdmin());
        self::assertFalse($support->hasOwner());
        self::assertSame(['v', 'o'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function createFromCapabWithoutPermanentMode(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertFalse($support->hasPermanentChannelMode());
        self::assertNull($support->getPermanentChannelModeLetter());
    }

    #[Test]
    public function createFromCapabWithPermanentMode(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r simple:P=P',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertTrue($support->hasPermanentChannelMode());
        self::assertSame('P', $support->getPermanentChannelModeLetter());
    }

    #[Test]
    public function createFromCapabWithoutRegisteredMode(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertFalse($support->hasChannelRegisteredMode());
        self::assertNull($support->getChannelRegisteredModeLetter());
    }

    #[Test]
    public function createFromCapabWithRegisteredMode(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r simple:inviteonly=i',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertSame('r', $support->getChannelRegisteredModeLetter());
    }

    #[Test]
    public function createFromCapabListModesFromCapab(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:banexception=e list:invex=I list:filter=g list:exemptchanops=X param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame(['b', 'e', 'I', 'g', 'X'], $support->getListModeLetters());
    }

    #[Test]
    public function createFromCapabChannelSettingUnsetWithoutParam(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:invex=I param-set:key=k param-set:limit=l param-set:flood=f prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r simple:inviteonly=i simple:moderated=m simple:topiclock=t',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertContains('r', $unsetWithoutParam);
        self::assertContains('i', $unsetWithoutParam);
        self::assertContains('m', $unsetWithoutParam);
        self::assertContains('t', $unsetWithoutParam);
        self::assertContains('l', $unsetWithoutParam);
        self::assertContains('f', $unsetWithoutParam);
        self::assertNotContains('k', $unsetWithoutParam);
    }

    #[Test]
    public function createFromCapabChannelSettingUnsetWithParam(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k param-set:limit=l prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame(['k'], $support->getChannelSettingModesUnsetWithParam());
    }

    #[Test]
    public function createFromCapabChannelSettingWithParamOnSet(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k param-set:limit=l param-set:flood=f prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $withParamOnSet);
        self::assertContains('l', $withParamOnSet);
        self::assertContains('f', $withParamOnSet);
    }

    #[Test]
    public function createFromCapabRealWorldData(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:banexception=e list:exemptchanops=X list:filter=g list:invex=I param-set:flood=f param-set:history=H param-set:joinflood=j param-set:limit=l param-set:key=k prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q simple:allowinvite=A simple:blockcolor=c simple:c_registered=r simple:inviteonly=i simple:moderated=m simple:noctcp=C simple:noextmsg=n simple:noknock=K simple:nonotice=T simple:private=p simple:reginvite=R simple:regmoderated=M simple:secret=s simple:sslonly=z simple:topiclock=t',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame(['v', 'h', 'o', 'a', 'q'], $support->getSupportedPrefixModes());
        self::assertTrue($support->hasHalfOp());
        self::assertTrue($support->hasAdmin());
        self::assertTrue($support->hasOwner());
        self::assertFalse($support->hasPermanentChannelMode());
        self::assertNull($support->getPermanentChannelModeLetter());
        self::assertTrue($support->hasChannelRegisteredMode());
        self::assertSame('r', $support->getChannelRegisteredModeLetter());
        self::assertTrue($support->hasVoice());
        self::assertTrue($support->hasOp());
    }

    #[Test]
    public function createFromCapabWithoutKeyStillProducesValidSupport(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame([], $support->getChannelSettingModesUnsetWithParam());
        self::assertSame([], $support->getChannelSettingModesWithParamOnSet());
    }

    #[Test]
    public function createFromCapabWithOwnerAlias(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b prefix:10000:voice=+v prefix:30000:op=@o prefix:50000:owner=~q simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertTrue($support->hasOwner());
        self::assertSame(['v', 'o', 'q'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function createFromCapabExcludesListModesFromChannelSettings(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:invex=I param-set:key=k simple:c_registered=r simple:inviteonly=i',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();
        self::assertNotContains('b', $unsetWithoutParam);
        self::assertNotContains('I', $unsetWithoutParam);
    }

    #[Test]
    public function createFromCapabExcludesPrefixModesFromChannelSettings(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();
        self::assertNotContains('v', $unsetWithoutParam);
        self::assertNotContains('o', $unsetWithoutParam);
    }

    #[Test]
    public function createFromCapabParamSetModeInListModesExcludedFromUnsetWithParam(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:customlist=z param-set:key=k param-set:custom=x simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame(['k'], $support->getChannelSettingModesUnsetWithParam());
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        self::assertNotContains('z', $withParamOnSet);
        self::assertContains('k', $withParamOnSet);
        self::assertContains('x', $withParamOnSet);
    }

    #[Test]
    public function createFromCapabParamSetModeAlreadyInSimpleModesNotDuplicated(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k param-set:limit=l simple:c_registered=r simple:limit=l',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();

        $lCount = array_count_values($unsetWithoutParam)['l'] ?? 0;
        self::assertSame(1, $lCount);
    }

    #[Test]
    public function createFromCapabWithUnknownPrefixNameGetsMappedToCorrectLetter(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b prefix:10000:voice=+v prefix:30000:op=@o prefix:99999:customrank=+c simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame(['v', 'o'], $support->getSupportedPrefixModes());
    }

    #[Test]
    public function createFromCapabParamSetExcludedFromUnsetWithParamWhenInList(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:invex=I param-set:key=k param-set:zombie=z simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('k', $withParamOnSet);
        self::assertContains('z', $withParamOnSet);
    }

    #[Test]
    public function createFromCapabSimpleModeInListModesExcluded(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:custom=z param-set:key=k simple:c_registered=r simple:custom=z simple:inviteonly=i',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertNotContains('z', $unsetWithoutParam);
        self::assertContains('r', $unsetWithoutParam);
        self::assertContains('i', $unsetWithoutParam);
    }

    #[Test]
    public function createFromCapabParamSetModeInListModesExcludedFromUnsetWithParamStrict(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:overlapping=k param-set:key=k simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);

        self::assertSame([], $support->getChannelSettingModesUnsetWithParam());
    }

    #[Test]
    public function createFromCapabParamSetModeInListModesExcludedFromWithParamOnSet(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:overlapping=k param-set:key=k param-set:limit=l simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();

        self::assertContains('l', $withParamOnSet);
        self::assertNotContains('k', $withParamOnSet);
    }

    #[Test]
    public function createFromCapabParamSetModeInListModesExcludedFromUnsetWithoutParam(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:overlapping=l param-set:key=k param-set:limit=l simple:c_registered=r',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertNotContains('l', $unsetWithoutParam);
    }

    #[Test]
    public function createFromCapabSimpleModeInPrefixModesExcluded(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:c_registered=r simple:v=v',
            'CAPAB END',
        ]);

        $support = $this->factory->createFromCapab($capab);
        $unsetWithoutParam = $support->getChannelSettingModesUnsetWithoutParam();

        self::assertNotContains('v', $unsetWithoutParam);
        self::assertContains('r', $unsetWithoutParam);
    }
}
