<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickProtectabilityResult;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\UseCase\Set\SetAutomaticModesHandler;
use App\NickServ\Application\UseCase\Set\SetAutomaticModesOutcome;
use App\NickServ\Application\UseCase\Set\SetAutomaticModesResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SetAutomaticModesHandler::class)]
#[CoversClass(SetAutomaticModesResult::class)]
final class SetAutomaticModesHandlerTest extends TestCase
{
    public function testReplacementClearAndNoOpOnlyPublishEffectiveChanges(): void
    {
        $nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
        new ReflectionProperty($nick, 'id')->setValue($nick, 12);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('save')->with($nick);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('Pedro', $nick));
        $catalog = $this->createStub(AutomaticModeCatalog::class);
        $catalog->method('available')->willReturn(['bot' => 'B', 'channel-deaf' => 'd', 'invisible' => 'i']);
        $users = $this->createStub(NickNetworkUserLookup::class);
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::exactly(2))->method('publish');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable());
        $handler = new SetAutomaticModesHandler($repo, $validator, $catalog, $users, $events, $clock);
        $actor = new NickOperationActor('Admin', 1, '001ADMIN', '001', 'admin@example.com', '127.0.0.1');
        self::assertSame(SetAutomaticModesOutcome::Changed, $handler->handle($actor, 'Pedro', 'BdiB')->outcome);
        self::assertSame(['bot', 'channel-deaf', 'invisible'], $nick->getAutomaticModes()->intentions);
        self::assertSame(SetAutomaticModesOutcome::Unchanged, $handler->handle($actor, 'Pedro', 'idB')->outcome);
        foreach (['+B', '-B', 'B d', 'o', ''] as $raw) {
            if ('' === $raw) {
                continue;
            }
            self::assertSame(SetAutomaticModesOutcome::InvalidModes, $handler->handle($actor, 'Pedro', $raw)->outcome);
            self::assertSame(['bot', 'channel-deaf', 'invisible'], $nick->getAutomaticModes()->intentions);
        }
        self::assertSame(SetAutomaticModesOutcome::Changed, $handler->handle($actor, 'Pedro', '')->outcome);
        self::assertSame([], $nick->getAutomaticModes()->intentions);
        self::assertSame(SetAutomaticModesOutcome::Unchanged, $handler->handle($actor, 'Pedro', '')->outcome);
    }

    public function testProtectsAllCentralizedAndConnectedOperatorTargetsWithoutSaving(): void
    {
        foreach (['root', 'ircop', 'service', 'connected', 'missing'] as $kind) {
            $nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
            $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
            $repo->expects(self::never())->method('save');
            $validator = $this->createStub(NickTargetValidator::class);
            $validator->method('validate')->willReturn(match ($kind) {
                'root' => NickProtectabilityResult::root('Pedro'), 'ircop' => NickProtectabilityResult::ircop('Pedro'),
                'service' => NickProtectabilityResult::service('Pedro'), 'missing' => NickProtectabilityResult::allowed('Pedro', null),
                default => NickProtectabilityResult::allowed('Pedro', $nick),
            });
            $users = $this->createStub(NickNetworkUserLookup::class);
            if ('connected' === $kind) {
                $users->method('findByNick')->willReturn(new NetworkUser('UID', 'Pedro', 'id', 'host', 'cloak', '*', true, true));
            }
            $events = $this->createMock(NickServEventPublisher::class);
            $events->expects(self::never())->method('publish');
            $handler = new SetAutomaticModesHandler($repo, $validator, $this->createStub(AutomaticModeCatalog::class), $users, $events, $this->createStub(Clock::class));
            self::assertSame(match ($kind) {
                'root' => SetAutomaticModesOutcome::Root, 'ircop','connected' => SetAutomaticModesOutcome::Ircop,
                'service' => SetAutomaticModesOutcome::Service, default => SetAutomaticModesOutcome::NotRegistered,
            }, $handler->handle(new NickOperationActor('Admin', 1, 'UID', '001', 'host', '*'), 'Pedro', 'B')->outcome);
            self::assertSame([], $nick->getAutomaticModes()->intentions);
        }
    }

    public function testUnknownSupportBlocksAssignmentButAllowsClearAndRemappingPreservesMeaning(): void
    {
        $nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
        new ReflectionProperty($nick, 'id')->setValue($nick, 12);
        $nick->changeAutomaticModes(new AutomaticModeProfile(['strip-formatting']));
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('save')->with($nick);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('Pedro', $nick));
        $available = null;
        $catalog = $this->createStub(AutomaticModeCatalog::class);
        $catalog->method('available')->willReturnCallback(static function () use (&$available): ?array { return $available; });
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::exactly(2))->method('publish');
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable());
        $handler = new SetAutomaticModesHandler($repo, $validator, $catalog, $this->createStub(NickNetworkUserLookup::class), $events, $clock);
        $actor = new NickOperationActor('Admin', 1, 'UID', '001', 'host', '*');
        self::assertSame(SetAutomaticModesOutcome::Unavailable, $handler->handle($actor, 'Pedro', 'B')->outcome);
        self::assertSame(['strip-formatting'], $nick->getAutomaticModes()->intentions);
        self::assertSame(SetAutomaticModesOutcome::Changed, $handler->handle($actor, 'Pedro', '')->outcome);
        $available = ['bot' => 'b'];
        self::assertSame(SetAutomaticModesOutcome::InvalidModes, $handler->handle($actor, 'Pedro', 'B')->outcome);
        self::assertSame(SetAutomaticModesOutcome::Changed, $handler->handle($actor, 'Pedro', 'bb')->outcome);
        self::assertSame(['bot'], $nick->getAutomaticModes()->intentions);
    }
}
