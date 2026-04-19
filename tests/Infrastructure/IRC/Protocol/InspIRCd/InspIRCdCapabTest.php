<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdCapab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InspIRCdCapab::class)]
final class InspIRCdCapabTest extends TestCase
{
    private const array REAL_WORLD_LINES = [
        'CAPAB START 1206',
        'CAPAB MODULES :banredirect callerid cloak=cloak-host=ares.wao4g4fe.inspircd.cloak.example&cloak-unix=ares%2Flqyuxcjd%2Fcloak.example&cloak-v4=ares.dlu5xhvg.ie6bl3ra.4ou4arwi.ip&cloak-v6=ares%3Aqfdyzjw6%3Aknvluhx7%3Awd4elspv%3Aip&host-parts=3&method=hmac-sha256&path-parts=1&prefix=ares&suffix=ip&using-psl=no filter=regex=glob globalload ircv3_ctctags services shun ',
        'CAPAB MODSUPPORT :account alltime channelban chghost=hostchars=-.%2F0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz chgident chgname globops knock realnameban sajoin sakick sanick sapart saquit silence swhois ',
        'CAPAB CHANMODES :list:ban=b list:banexception=e list:exemptchanops=X list:filter=g list:invex=I param-set:flood=f param-set:history=H param-set:joinflood=j param-set:limit=l param-set:key=k prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q simple:allowinvite=A simple:blockcolor=c simple:c_registered=r simple:inviteonly=i simple:moderated=m simple:noctcp=C simple:noextmsg=n simple:noknock=K simple:nonotice=T simple:private=p simple:reginvite=R simple:regmoderated=M simple:secret=s simple:sslonly=z simple:topiclock=t',
        'CAPAB USERMODES :param-set:snomask=s simple:bot=B simple:callerid=g simple:cloak=x simple:deaf_commonchan=c simple:hidechans=I simple:invisible=i simple:nohistory=N simple:oper=o simple:regdeaf=R simple:servprotect=k simple:sslqueries=z simple:u_noctcp=T simple:u_registered=r simple:wallops=w',
        'CAPAB EXTBANS :matching:fingerprint=z matching:realmask=a acting:nonotice=T acting:noctcp=C matching:channel=j matching:realname=r matching:gateway=w acting:blockcolor=c acting:blockinvite=A matching:unauthed=U matching:account=R',
        'CAPAB CAPABILITIES :CHALLENGE=z4HtmrVhbXmZ4xiJFxNw EXTBANFORMAT=name CASEMAPPING=ascii MAXHOST=64 MAXCHANNEL=60 MAXKEY=30 MAXNICK=30 MAXKICK=300 MAXMODES=20 MAXQUIT=300 MAXREAL=130 MAXUSER=10 MAXAWAY=200 MAXLINE=512 MAXTOPIC=330',
        'CAPAB END',
    ];

    private const array MINIMAL_LINES = [
        'CAPAB START 1206',
        'CAPAB CHANMODES :list:ban=b list:banexception=e list:invex=I param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:moderated=m simple:c_registered=r simple:topiclock=t',
        'CAPAB MODSUPPORT :services',
        'CAPAB CAPABILITIES :CASEMAPPING=ascii MAXCHANNEL=60',
        'CAPAB END',
    ];

    private const array NO_PERMANENT_NO_RANKS_LINES = [
        'CAPAB START 1206',
        'CAPAB CHANMODES :list:ban=b list:banexception=e list:invex=I param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:moderated=m simple:c_registered=r simple:topiclock=t',
        'CAPAB END',
    ];

    #[Test]
    public function fromCapabLinesParsesRealWorldChanmodes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasPrefixMode('voice'));
        self::assertTrue($capab->hasPrefixMode('halfop'));
        self::assertTrue($capab->hasPrefixMode('op'));
        self::assertTrue($capab->hasPrefixMode('admin'));
        self::assertTrue($capab->hasPrefixMode('founder'));
        self::assertFalse($capab->hasPrefixMode('owner'));
    }

    #[Test]
    public function fromCapabLinesParsesPrefixModeLevels(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $prefixes = $capab->getPrefixModes();

        self::assertSame(10000, $prefixes['voice']);
        self::assertSame(20000, $prefixes['halfop']);
        self::assertSame(30000, $prefixes['op']);
        self::assertSame(40000, $prefixes['admin']);
        self::assertSame(50000, $prefixes['founder']);
    }

    #[Test]
    public function fromCapabLinesParsesListModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasListMode('b'));
        self::assertTrue($capab->hasListMode('e'));
        self::assertTrue($capab->hasListMode('I'));
        self::assertTrue($capab->hasListMode('g'));
        self::assertTrue($capab->hasListMode('X'));
        self::assertFalse($capab->hasListMode('z'));
    }

    #[Test]
    public function fromCapabLinesParsesParamSetModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasParamSetMode('k'));
        self::assertTrue($capab->hasParamSetMode('l'));
        self::assertTrue($capab->hasParamSetMode('f'));
        self::assertTrue($capab->hasParamSetMode('j'));
        self::assertTrue($capab->hasParamSetMode('H'));
        self::assertFalse($capab->hasParamSetMode('i'));
    }

    #[Test]
    public function fromCapabLinesParsesSimpleModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasSimpleMode('i'));
        self::assertTrue($capab->hasSimpleMode('m'));
        self::assertTrue($capab->hasSimpleMode('r'));
        self::assertTrue($capab->hasSimpleMode('t'));
        self::assertTrue($capab->hasSimpleMode('A'));
        self::assertTrue($capab->hasSimpleMode('c'));
        self::assertFalse($capab->hasSimpleMode('P'));
    }

    #[Test]
    public function fromCapabLinesParsesModSupport(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasModule('account'));
        self::assertTrue($capab->hasModule('chghost'));
        self::assertTrue($capab->hasModule('services'));
        self::assertFalse($capab->hasModule('nonexistent'));
    }

    #[Test]
    public function fromCapabLinesParsesUserModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $modes = $capab->getUserModes();

        self::assertContains('i', $modes);
        self::assertContains('o', $modes);
        self::assertContains('x', $modes);
        self::assertContains('r', $modes);
    }

    #[Test]
    public function fromCapabLinesParsesExtbans(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $bans = $capab->getExtbans();

        self::assertContains('z', $bans);
        self::assertContains('a', $bans);
        self::assertContains('R', $bans);
        self::assertContains('j', $bans);
    }

    #[Test]
    public function fromCapabLinesParsesCapabilities(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertSame('ascii', $capab->getCapability('CASEMAPPING'));
        self::assertSame('60', $capab->getCapability('MAXCHANNEL'));
        self::assertSame('30', $capab->getCapability('MAXNICK'));
        self::assertNull($capab->getCapability('NONEXISTENT'));
    }

    #[Test]
    public function fromCapabLinesMinimalSetNoHalfopAdminOwner(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::MINIMAL_LINES);

        self::assertTrue($capab->hasPrefixMode('voice'));
        self::assertTrue($capab->hasPrefixMode('op'));
        self::assertFalse($capab->hasPrefixMode('halfop'));
        self::assertFalse($capab->hasPrefixMode('admin'));
        self::assertFalse($capab->hasPrefixMode('founder'));
        self::assertFalse($capab->hasSimpleMode('P'));
    }

    #[Test]
    public function fromCapabLinesNoPermanentNoRanks(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::NO_PERMANENT_NO_RANKS_LINES);

        self::assertFalse($capab->hasPrefixMode('halfop'));
        self::assertFalse($capab->hasPrefixMode('admin'));
        self::assertFalse($capab->hasPrefixMode('founder'));
        self::assertFalse($capab->hasSimpleMode('P'));
        self::assertTrue($capab->hasSimpleMode('r'));
    }

    #[Test]
    public function fromCapabLinesWithEmptyLinesIsGraceful(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            '',
            'CAPAB CHANMODES :list:ban=b prefix:10000:voice=+v simple:inviteonly=i',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasPrefixMode('voice'));
        self::assertTrue($capab->hasListMode('b'));
        self::assertTrue($capab->hasSimpleMode('i'));
    }

    #[Test]
    public function fromCapabLinesWithNoCapabLinesReturnsEmptyCapab(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(['CAPAB START 1206', 'CAPAB END']);

        self::assertSame([], $capab->getPrefixModes());
        self::assertSame([], $capab->getListModes());
        self::assertSame([], $capab->getSimpleModes());
        self::assertSame([], $capab->getModules());
        self::assertSame([], $capab->getModSupport());
        self::assertSame([], $capab->getUserModes());
        self::assertSame([], $capab->getExtbans());
        self::assertSame([], $capab->getCapabilities());
    }

    #[Test]
    public function fromCapabLinesIgnoresNonCapabLines(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b prefix:10000:voice=+v',
            ':994 PING 0A0',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasPrefixMode('voice'));
        self::assertTrue($capab->hasListMode('b'));
    }

    #[Test]
    public function getListModesReturnsAllParsedListModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertSame(['b', 'e', 'X', 'g', 'I'], $capab->getListModes());
    }

    #[Test]
    public function getParamSetModesReturnsAllParsedParamSetModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertSame(['f', 'H', 'j', 'l', 'k'], $capab->getParamSetModes());
    }

    #[Test]
    public function getSimpleModesReturnsAllParsedSimpleModes(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $simple = $capab->getSimpleModes();

        self::assertContains('i', $simple);
        self::assertContains('m', $simple);
        self::assertContains('r', $simple);
        self::assertContains('t', $simple);
        self::assertContains('A', $simple);
        self::assertContains('c', $simple);
        self::assertContains('p', $simple);
        self::assertContains('s', $simple);
        self::assertContains('z', $simple);
    }

    #[Test]
    public function getCapabilitiesReturnsAllParsedCapabilities(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $caps = $capab->getCapabilities();

        self::assertArrayHasKey('CASEMAPPING', $caps);
        self::assertArrayHasKey('MAXCHANNEL', $caps);
        self::assertArrayHasKey('MAXNICK', $caps);
        self::assertSame('ascii', $caps['CASEMAPPING']);
        self::assertSame('60', $caps['MAXCHANNEL']);
    }

    #[Test]
    public function getModSupportReturnsModuleList(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::MINIMAL_LINES);

        self::assertSame(['services'], $capab->getModSupport());
    }

    #[Test]
    public function hasModuleIsCaseInsensitive(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::MINIMAL_LINES);

        self::assertTrue($capab->hasModule('Services'));
        self::assertTrue($capab->hasModule('SERVICES'));
        self::assertTrue($capab->hasModule('services'));
    }

    #[Test]
    public function hasModuleFindsModulesFromModulesLine(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasModule('services'));
        self::assertTrue($capab->hasModule('shun'));
        self::assertTrue($capab->hasModule('cloak'));
    }

    #[Test]
    public function getModulesReturnsParsedModulesList(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);
        $modules = $capab->getModules();

        self::assertContains('services', $modules);
        self::assertContains('shun', $modules);
    }

    #[Test]
    public function hasPrefixModeIsCaseInsensitive(): void
    {
        $capab = InspIRCdCapab::fromCapabLines(self::REAL_WORLD_LINES);

        self::assertTrue($capab->hasPrefixMode('Voice'));
        self::assertTrue($capab->hasPrefixMode('VOICE'));
        self::assertTrue($capab->hasPrefixMode('HalfOp'));
    }

    #[Test]
    public function parseChanmodesWithEmptyPartsIsSkipped(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :  list:ban=b   prefix:10000:voice=+v   simple:inviteonly=i   ',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasListMode('b'));
        self::assertTrue($capab->hasPrefixMode('voice'));
        self::assertTrue($capab->hasSimpleMode('i'));
    }

    #[Test]
    public function parseChanmodesWithUnknownCategoryIsIgnored(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :unknown:type=x list:ban=b prefix:10000:voice=+v simple:inviteonly=i',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasListMode('b'));
        self::assertTrue($capab->hasSimpleMode('i'));
    }

    #[Test]
    public function parseChanmodesWithoutEqualsInAssignmentReturnsNoLetter(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b list:malformed prefix:10000:voice=+v simple:inviteonly=i',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasListMode('b'));
        self::assertSame(['voice' => 10000], $capab->getPrefixModes());
        self::assertTrue($capab->hasSimpleMode('i'));
    }

    #[Test]
    public function parsePrefixNameWithoutEqualsReturnsEmpty(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :prefix:10000:malformed list:ban=b',
            'CAPAB END',
        ]);

        self::assertSame([], $capab->getPrefixModes());
        self::assertTrue($capab->hasListMode('b'));
    }

    #[Test]
    public function parseModeLetterWithLongSuffixReturnsEmpty(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:ban=b simple:mode=abc prefix:10000:voice=+v',
            'CAPAB END',
        ]);

        self::assertTrue($capab->hasListMode('b'));
        self::assertFalse($capab->hasSimpleMode('a'));
    }

    #[Test]
    public function parseModeLetterWithoutEquaksReturnsEmpty(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :list:malformed simple:test=r prefix:10000:voice=+v',
            'CAPAB END',
        ]);

        self::assertSame([], $capab->getListModes());
    }

    #[Test]
    public function fromCapabLinesParsesUserModesWithEmptyParts(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB USERMODES :  simple:bot=B   simple:invisible=i   param-set:snomask=s   ',
            'CAPAB END',
        ]);

        $modes = $capab->getUserModes();
        self::assertContains('B', $modes);
        self::assertContains('i', $modes);
        self::assertContains('s', $modes);
    }

    #[Test]
    public function fromCapabLinesParsesUserModesWithMalformedEntry(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB USERMODES :simple:bot=B malformed simple:invisible=i',
            'CAPAB END',
        ]);

        $modes = $capab->getUserModes();
        self::assertContains('B', $modes);
        self::assertContains('i', $modes);
    }

    #[Test]
    public function fromCapabLinesParsesUserModesWithPrefixStyleAssignment(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB USERMODES :simple:oper=+o simple:bot=B simple:invisible=i',
            'CAPAB END',
        ]);

        $modes = $capab->getUserModes();
        self::assertContains('o', $modes);
        self::assertContains('B', $modes);
        self::assertContains('i', $modes);
    }

    #[Test]
    public function fromCapabLinesParsesExtbansWithEmptyParts(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB EXTBANS :  matching:fingerprint=z   matching:account=R   ',
            'CAPAB END',
        ]);

        $bans = $capab->getExtbans();
        self::assertContains('z', $bans);
        self::assertContains('R', $bans);
    }

    #[Test]
    public function fromCapabLinesParsesExtbansWithMalformedEntry(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB EXTBANS :matching:fingerprint=z malformed matching:account=R',
            'CAPAB END',
        ]);

        $bans = $capab->getExtbans();
        self::assertContains('z', $bans);
        self::assertContains('R', $bans);
    }

    #[Test]
    public function fromCapabLinesParsesCapabilitiesWithNoEqualSign(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CAPABILITIES :CASEMAPPING=ascii NOEQUALSSIGN MAXCHANNEL=60',
            'CAPAB END',
        ]);

        self::assertSame('ascii', $capab->getCapability('CASEMAPPING'));
        self::assertSame('60', $capab->getCapability('MAXCHANNEL'));
        self::assertNull($capab->getCapability('NOEQUALSSIGN'));
    }

    #[Test]
    public function fromCapabLinesParsesCapabilitiesWithEmptyParts(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CAPABILITIES :  CASEMAPPING=ascii   MAXCHANNEL=60   ',
            'CAPAB END',
        ]);

        self::assertSame('ascii', $capab->getCapability('CASEMAPPING'));
        self::assertSame('60', $capab->getCapability('MAXCHANNEL'));
    }

    #[Test]
    public function parseChanmodesWithPrefixModeWithoutLevel(): void
    {
        $capab = InspIRCdCapab::fromCapabLines([
            'CAPAB START 1206',
            'CAPAB CHANMODES :prefix::voice=+v list:ban=b',
            'CAPAB END',
        ]);

        self::assertSame(['voice' => 0], $capab->getPrefixModes());
    }
}
