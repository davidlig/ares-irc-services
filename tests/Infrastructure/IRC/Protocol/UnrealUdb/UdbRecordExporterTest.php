<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\ChanServ\ValueObject\ChannelStatus;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UdbRecordExporter::class)]
final class UdbRecordExporterTest extends TestCase
{
    private RegisteredNickRepositoryInterface $nickRepository;

    private RegisteredChannelRepositoryInterface $channelRepository;

    private ChannelAccessRepositoryInterface $accessRepository;

    private OperIrcopRepositoryInterface $ircopRepository;

    private ChannelLookupPort $channelLookup;

    private UdbRecordExporter $exporter;

    protected function setUp(): void
    {
        $this->nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $this->channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $this->accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $this->ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);

        $this->exporter = new UdbRecordExporter(
            $this->nickRepository,
            $this->channelRepository,
            $this->accessRepository,
            $this->ircopRepository,
            $this->createStub(GlineRepositoryInterface::class),
            $this->channelLookup,
            $this->createModeSupportProvider(),
        );
    }

    #[Test]
    public function compatiblePasswordHashesAreRecognized(): void
    {
        self::assertTrue($this->exporter->isCompatiblePasswordHash('argon2id:$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$hash'));
        self::assertTrue($this->exporter->isCompatiblePasswordHash('crypt:$6$rounds=656000$salt$hash'));
        self::assertTrue($this->exporter->isCompatiblePasswordHash('sha256:' . str_repeat('a', 64)));
        self::assertTrue($this->exporter->isCompatiblePasswordHash('sha256:' . str_repeat('A', 64)));
    }

    #[Test]
    public function incompatiblePasswordHashesAreRejected(): void
    {
        self::assertFalse($this->exporter->isCompatiblePasswordHash('$2y$10$bcryptjunk'));
        self::assertFalse($this->exporter->isCompatiblePasswordHash('crypt:'));
        self::assertFalse($this->exporter->isCompatiblePasswordHash('sha256:notahash'));
        self::assertFalse($this->exporter->isCompatiblePasswordHash('md5:deadbeef'));
    }

    #[Test]
    public function effectiveVhostPrefersRoleForcedPattern(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'personal.tld');
        $role = OperRole::create('netadmin');
        $roleProp = new ReflectionClass(OperRole::class)->getProperty('forcedVhostPattern');
        $roleProp->setValue($role, 'staff.example.net');

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(OperIrcop::create($nick->getId(), $role));
        $exporter = $this->createExporterWithIrcopRepo($ircopRepo);

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
    public function nickRecordsIncludeCompatiblePassVhostAndOper(): void
    {
        $nick = $this->createNick('davidlig', vhost: 'david.tld', passwordHash: 'sha256:' . str_repeat('a', 64));
        $role = OperRole::create('netadmin');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(OperIrcop::create($nick->getId(), $role));
        $exporter = $this->createExporterWithIrcopRepo($ircopRepo);

        self::assertSame([
            'davidlig::pass' => 'sha256:' . str_repeat('a', 64),
            'davidlig::vhost' => 'david.tld',
            'davidlig::oper' => 'NETADMIN',
        ], $exporter->nickRecords($nick));
    }

    #[Test]
    public function nickRecordsSkipIncompatibleHashesAndMissingVhost(): void
    {
        $nick = $this->createNick('plainnick', passwordHash: '$2y$10$bcrypt');

        self::assertSame([], $this->exporter->nickRecords($nick));
    }

    #[Test]
    public function channelRecordsBuildFullActiveProfile(): void
    {
        $founder = $this->createNick('founder');
        $channel = $this->createChannel('#chan', founderNickId: 7, topic: 'Welcome', mlockActive: true, topicLock: true);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 7 === $id ? $founder : null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([
            new ChannelAccess(1, 7, 300),
        ]);

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#chan', '+rPnt', null, 2, timestamp: 111, modeParams: ['l' => '50']));

        $exporter = new UdbRecordExporter(
            $nickRepo,
            $this->channelRepository,
            $accessRepo,
            $this->ircopRepository,
            $this->createStub(GlineRepositoryInterface::class),
            $lookup,
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
        $channel = $this->createChannel('#suspended', status: ChannelStatus::Suspended);

        $records = $this->exporter->channelRecords($channel);

        self::assertSame(['#suspended::suspended' => '1'], $records);
        self::assertSame(0, $this->exporter->channelOptions($channel));
    }

    #[Test]
    public function forbiddenChannelExportsOnlyForbidRecord(): void
    {
        $channel = $this->createChannel('#bad', status: ChannelStatus::Forbidden, forbiddenReason: 'spam');

        self::assertSame(['#bad::forbid' => 'spam'], $this->exporter->channelRecords($channel));
    }

    #[Test]
    public function forbiddenChannelWithoutReasonExportsNothing(): void
    {
        $channel = $this->createChannel('#bad', status: ChannelStatus::Forbidden);

        self::assertSame([], $this->exporter->channelRecords($channel));
    }

    #[Test]
    public function channelOptionsBitsMatchUdbSemantics(): void
    {
        self::assertSame(8, $this->exporter->channelOptions($this->createChannel('#plain')));
        self::assertSame(10, $this->exporter->channelOptions($this->createChannel('#m', mlockActive: true)));
        self::assertSame(12, $this->exporter->channelOptions($this->createChannel('#t', topicLock: true)));
        self::assertSame(14, $this->exporter->channelOptions($this->createChannel('#mt', mlockActive: true, topicLock: true)));
        self::assertSame(6, $this->exporter->channelOptions($this->createChannel('#pd', status: ChannelStatus::PendingDeletion, mlockActive: true, topicLock: true)));
    }

    #[Test]
    public function accessEntriesWithUnknownNicksAreSkipped(): void
    {
        $channel = $this->createChannel('#chan');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([new ChannelAccess(1, 999, 100)]);

        $exporter = new UdbRecordExporter(
            $this->nickRepository,
            $this->channelRepository,
            $accessRepo,
            $this->ircopRepository,
            $this->createStub(GlineRepositoryInterface::class),
            $this->channelLookup,
            $this->createModeSupportProvider(),
        );

        self::assertSame([], $exporter->accessRecord($channel, new ChannelAccess(1, 999, 100)));
        // The channel profile still exports everything else (options), just without the access entry.
        self::assertSame(['#chan::options' => '*8'], $exporter->channelRecords($channel));
    }

    #[Test]
    public function glineRecordsIncludeRootReasonAndDuration(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-30 10:00:00');
        $gline = Gline::create('*@bad.example', null, 'abuse', new DateTimeImmutable('2026-08-30 11:00:00'));
        $createdAtProp = new ReflectionClass(Gline::class)->getProperty('createdAt');
        $createdAtProp->setValue($gline, $createdAt);

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
        ], $this->exporter->glineRecords(Gline::create('*@bad.example', null, 'abuse')));
    }

    #[Test]
    public function glineWithoutReasonExportsNothing(): void
    {
        self::assertSame([], $this->exporter->glineRecords(Gline::create('*@bad.example')));
    }

    private function createExporterWithIrcopRepo(OperIrcopRepositoryInterface $ircopRepo): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $this->nickRepository,
            $this->channelRepository,
            $this->accessRepository,
            $ircopRepo,
            $this->createStub(GlineRepositoryInterface::class),
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

    private function createNick(string $nickname, ?string $vhost = null, ?string $passwordHash = null): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, $passwordHash ?? 'argon2id:$argon2id$hash', $nickname . '@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($nick, 42);
        if (null !== $vhost) {
            $reflection->getProperty('vhost')->setValue($nick, $vhost);
        }

        return $nick;
    }

    private function createChannel(
        string $name,
        int $founderNickId = 7,
        ?string $topic = null,
        bool $mlockActive = false,
        bool $topicLock = false,
        ChannelStatus $status = ChannelStatus::Active,
        ?string $forbiddenReason = null,
    ): RegisteredChannel {
        $channel = RegisteredChannel::register($name, $founderNickId, 'desc');
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $reflection->getProperty('id')->setValue($channel, 1);
        if (null !== $topic) {
            $channel->updateTopic($topic);
        }
        if ($mlockActive) {
            $channel->configureMlock(true, '+nt');
        }
        if ($topicLock) {
            $channel->configureTopicLock(true);
        }
        $reflection->getProperty('status')->setValue($channel, $status);
        if (null !== $forbiddenReason) {
            $reflection->getProperty('forbiddenReason')->setValue($channel, $forbiddenReason);
        }

        return $channel;
    }
}
