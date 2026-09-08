<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelAccessProjection;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjection;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UdbRecordExporter::class)]
final class UdbRecordExporterTest extends TestCase
{
    private NickProjectionQuery&Stub $nicks;

    private ChannelProjectionQuery&Stub $channels;

    private OperatorNetworkProjectionQuery&Stub $operators;

    private GlineProjectionQuery&Stub $glines;

    private ChannelLookupPort $channelLookup;

    private UdbRecordExporter $exporter;

    protected function setUp(): void
    {
        $this->nicks = $this->createStub(NickProjectionQuery::class);
        $this->channels = $this->createStub(ChannelProjectionQuery::class);
        $this->operators = $this->createStub(OperatorNetworkProjectionQuery::class);
        $this->glines = $this->createStub(GlineProjectionQuery::class);
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);

        $this->exporter = new UdbRecordExporter(
            $this->nicks,
            $this->channels,
            $this->operators,
            $this->glines,
            $this->channelLookup,
            $this->createModeSupportProvider(),
        );
    }

    #[Test]
    public function getChannelLookupReturnsConfiguredPort(): void
    {
        self::assertSame($this->channelLookup, $this->exporter->getChannelLookup());
    }

    #[Test]
    public function bcryptSqlHashesAreRelabeledAsCryptForUdb(): void
    {
        $hash = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';

        self::assertSame('crypt:' . $hash, $this->exporter->toUdbPasswordHash($hash));
    }

    #[Test]
    public function nullAndNonBcryptHashesAreNotProjected(): void
    {
        self::assertNull($this->exporter->toUdbPasswordHash(null));
        self::assertNull($this->exporter->toUdbPasswordHash(''));
        self::assertNull($this->exporter->toUdbPasswordHash('$2y$10$bcryptjunk'));
        self::assertNull($this->exporter->toUdbPasswordHash('sha256:' . str_repeat('a', 64)));
        self::assertNull($this->exporter->toUdbPasswordHash('argon2id:$argon2id$hash'));
        self::assertNull($this->exporter->toUdbPasswordHash('md5:deadbeef'));
    }

    #[Test]
    public function effectiveVhostPrefersRoleForcedPattern(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'personal.tld');
        $operators = $this->createStub(OperatorNetworkProjectionQuery::class);
        $operators->method('findForNick')->willReturn(new OperatorNetworkProjection($nick->id, 'davidlig.staff.example.net', null));
        $exporter = $this->createExporterWithOperators($operators);

        self::assertSame('davidlig.staff.example.net', $exporter->effectiveVhost($nick));
    }

    #[Test]
    public function effectiveVhostFallsBackToPersonalVhost(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'personal.tld');

        self::assertSame('personal.tld', $this->exporter->effectiveVhost($nick));
    }

    #[Test]
    public function effectiveVhostIsNullWithoutAnyVhost(): void
    {
        self::assertNull($this->exporter->effectiveVhost($this->createNick('plainnick')));
    }

    #[Test]
    public function nickRecordsIncludeProjectedPassVhostAndOper(): void
    {
        $bcryptHash = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';
        $nick = $this->createNick('davidlig', vhost: 'david.tld', passwordHash: $bcryptHash);
        $operators = $this->createStub(OperatorNetworkProjectionQuery::class);
        $operators->method('findForNick')->willReturn(new OperatorNetworkProjection($nick->id, null, 'services:netadmin'));
        $exporter = $this->createExporterWithOperators($operators);

        self::assertSame([
            'davidlig::pass' => 'crypt:' . $bcryptHash,
            'davidlig::vhost' => 'david.tld',
            'davidlig::oper' => 'services:netadmin',
        ], $exporter->nickRecords($nick));
    }

    #[Test]
    public function nickRecordsSkipIncompatibleHashesAndMissingVhost(): void
    {
        $nick = $this->createNick('plainnick', passwordHash: '$2y$10$bcrypt');

        self::assertSame([], $this->exporter->nickRecords($nick));
    }

    #[Test]
    public function nickRecordsProjectBcryptSqlHashesIntoUdbForm(): void
    {
        $hash = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';
        $nick = $this->createNick('plainnick', passwordHash: $hash);

        self::assertSame(['plainnick::pass' => 'crypt:' . $hash], $this->exporter->nickRecords($nick));
    }

    #[Test]
    public function channelRecordsBuildFullActiveProfile(): void
    {
        $founder = $this->createNick('founder');
        $nicks = $this->createStub(NickProjectionQuery::class);
        $nicks->method('findById')->willReturnCallback(static fn (int $id): ?NickProjection => 7 === $id ? $founder : null);
        $channel = $this->createChannel(
            '#chan',
            founderNickId: 7,
            topic: 'Welcome',
            mlockActive: true,
            topicLock: true,
            access: [new ChannelAccessProjection(7, 300)],
        );

        $exporter = new UdbRecordExporter(
            $nicks,
            $this->channels,
            $this->operators,
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createModeSupportProvider(),
        );

        $records = $exporter->channelRecords($channel);

        self::assertSame([
            '#chan::founder' => 'founder',
            '#chan::topic' => 'Welcome',
            '#chan::modes' => '+nt',
            '#chan::options' => '*14',
            '#chan::access::founder' => '300',
        ], $records);
    }

    #[Test]
    public function emptyTopicsAreNeverExported(): void
    {
        $channel = $this->createChannel('#chan', founderNickId: 7, topic: '');

        $records = $this->exporter->channelRecords($channel);

        self::assertArrayNotHasKey('#chan::topic', $records);
    }

    #[Test]
    public function suspendedChannelExportsSuspendedRecordWithoutPersistentBit(): void
    {
        $channel = $this->createChannel('#suspended', suspended: true);

        $records = $this->exporter->channelRecords($channel);

        self::assertSame(['#suspended::suspended' => '1'], $records);
        self::assertSame(0, $this->exporter->channelOptions($channel));
    }

    #[Test]
    public function forbiddenChannelExportsOnlyForbidRecord(): void
    {
        $channel = $this->createChannel('#bad', forbidden: true, forbiddenReason: 'spam');

        self::assertSame(['#bad::forbid' => 'spam'], $this->exporter->channelRecords($channel));
    }

    #[Test]
    public function forbiddenChannelWithoutReasonExportsNothing(): void
    {
        $channel = $this->createChannel('#bad', forbidden: true);

        self::assertSame([], $this->exporter->channelRecords($channel));
    }

    #[Test]
    public function channelOptionsBitsMatchUdbSemantics(): void
    {
        self::assertSame(8, $this->exporter->channelOptions($this->createChannel('#plain')));
        self::assertSame(10, $this->exporter->channelOptions($this->createChannel('#m', mlockActive: true)));
        self::assertSame(12, $this->exporter->channelOptions($this->createChannel('#t', topicLock: true)));
        self::assertSame(14, $this->exporter->channelOptions($this->createChannel('#mt', mlockActive: true, topicLock: true)));
        self::assertSame(6, $this->exporter->channelOptions($this->createChannel('#pd', pendingDeletion: true, mlockActive: true, topicLock: true)));
    }

    #[Test]
    public function accessEntriesWithUnknownNicksAreSkipped(): void
    {
        $access = new ChannelAccessProjection(999, 100);
        $channel = $this->createChannel('#chan', access: [$access]);

        self::assertSame([], $this->exporter->accessRecord($channel, $access));
        // The channel profile still exports everything else (options), just without the access entry.
        self::assertSame(['#chan::options' => '*8'], $this->exporter->channelRecords($channel));
    }

    #[Test]
    public function glineRecordsIncludeRootReasonAndDuration(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-30 10:00:00');
        $gline = new GlineProjection('*@bad.example', 'abuse', $createdAt, new DateTimeImmutable('2026-08-30 11:00:00'));

        self::assertSame([
            'G::*@bad.example' => 'abuse',
            'G::*@bad.example::reason' => 'abuse',
            'G::*@bad.example::duration' => '*3600',
        ], $this->exporter->glineRecords($gline));
    }

    #[Test]
    public function permanentGlineHasNoDurationRecord(): void
    {
        self::assertSame([
            'G::*@bad.example' => 'abuse',
            'G::*@bad.example::reason' => 'abuse',
        ], $this->exporter->glineRecords(new GlineProjection('*@bad.example', 'abuse', new DateTimeImmutable('2026-08-30 10:00:00'), null)));
    }

    #[Test]
    public function glineWithoutReasonExportsNothing(): void
    {
        self::assertSame([], $this->exporter->glineRecords(new GlineProjection('*@bad.example', null, new DateTimeImmutable('2026-08-30 10:00:00'), null)));
    }

    private function createExporterWithOperators(OperatorNetworkProjectionQuery $operators): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $this->nicks,
            $this->channels,
            $operators,
            $this->createStub(GlineProjectionQuery::class),
            $this->channelLookup,
            $this->createModeSupportProvider(),
        );
    }

    private function createModeSupportProvider(): ActiveChannelModeSupportProviderInterface
    {
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['l', 'k', 'j', 'f', 'L']);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        return $provider;
    }

    #[Test]
    public function encodedBlockRecordsEncodesSqlOwnedBlockPaths(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'david.tld');
        $this->nicks->method('all')->willReturn([$nick]);

        $encoded = UdbPathCodec::encodePath(['davidlig', 'vhost']);

        self::assertNotNull($encoded);
        self::assertSame([$encoded => 'david.tld'], $this->exporter->encodedBlockRecords(UdbBlock::Nicks));
    }

    #[Test]
    public function encodedBlockRecordsReturnsEmptyForWireOwnedBlock(): void
    {
        self::assertSame([], $this->exporter->encodedBlockRecords(UdbBlock::Ips));
    }

    #[Test]
    public function encodedBlockRecordsAggregatesAllChannels(): void
    {
        $this->channels->method('all')->willReturn([
            $this->createChannel('#first'),
            $this->createChannel('#second', suspended: true),
        ]);
        $firstOptions = UdbPathCodec::encodePath(['#first', 'options']);
        $secondSuspended = UdbPathCodec::encodePath(['#second', 'suspended']);

        self::assertNotNull($firstOptions);
        self::assertNotNull($secondSuspended);
        self::assertSame([
            $firstOptions => '*8',
            $secondSuspended => '1',
        ], $this->exporter->encodedBlockRecords(UdbBlock::Channels));
    }

    #[Test]
    public function encodedBlockRecordsAggregatesAllActiveGlines(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-30 10:00:00');
        $this->glines->method('active')->willReturn([
            new GlineProjection('*@first.example', 'first reason', $createdAt, null),
            new GlineProjection('*@second.example', 'second reason', $createdAt, null),
        ]);
        $firstRoot = UdbPathCodec::encodePath(['G', '*@first.example']);
        $firstReason = UdbPathCodec::encodePath(['G', '*@first.example', 'reason']);
        $secondRoot = UdbPathCodec::encodePath(['G', '*@second.example']);
        $secondReason = UdbPathCodec::encodePath(['G', '*@second.example', 'reason']);

        self::assertNotNull($firstRoot);
        self::assertNotNull($firstReason);
        self::assertNotNull($secondRoot);
        self::assertNotNull($secondReason);
        self::assertSame([
            $firstRoot => 'first reason',
            $firstReason => 'first reason',
            $secondRoot => 'second reason',
            $secondReason => 'second reason',
        ], $this->exporter->encodedBlockRecords(UdbBlock::Lines));
    }

    #[Test]
    public function encodedBlockRecordsRejectsAnInvalidSqlExportRecord(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'bad vhost with spaces');
        $this->nicks->method('all')->willReturn([$nick]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current SQL export contains an invalid N-block record.');

        $this->exporter->encodedBlockRecords(UdbBlock::Nicks);
    }

    private function createNick(string $nickname, ?string $vhost = null, ?string $passwordHash = null): NickProjection
    {
        return new NickProjection(42, $nickname, $passwordHash ?? 'argon2id:$argon2id$hash', $vhost);
    }

    /**
     * @param array<string, string>         $mlockParams
     * @param list<ChannelAccessProjection> $access
     */
    private function createChannel(
        string $name,
        int $founderNickId = 7,
        ?string $topic = null,
        bool $mlockActive = false,
        bool $topicLock = false,
        bool $forbidden = false,
        ?string $forbiddenReason = null,
        bool $suspended = false,
        bool $pendingDeletion = false,
        string $mlock = '+nt',
        array $mlockParams = [],
        array $access = [],
    ): ChannelProjection {
        return new ChannelProjection(
            id: 1,
            name: $name,
            founderNickId: $founderNickId,
            topic: $topic,
            mlockActive: $mlockActive,
            mlock: $mlock,
            mlockParams: $mlockParams,
            topicLock: $topicLock,
            forbidden: $forbidden,
            forbiddenReason: $forbiddenReason,
            suspended: $suspended,
            pendingDeletion: $pendingDeletion,
            access: $access,
        );
    }
}
