<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkDTO;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\NickServ\Adapter\In\Event\NickAutomaticModesSubscriber;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\AutomaticModeActions;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickAutomaticModesChangedEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\NickServ\Application\Service\AutomaticModeSessionService;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(NickAutomaticModesSubscriber::class)]
#[CoversClass(NickAutomaticModesChangedEvent::class)]
final class NickAutomaticModesSubscriberTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<NickHistory> */
    private array $history = [];

    public function testHistoryHasActorAndBeforeAfterAndPreferenceChangeAppliesImmediately(): void
    {
        $dispatcher = $this->dispatcher();
        $time = new DateTimeImmutable('2026-11-01T10:00:00Z');
        $dispatcher->dispatch(new NickAutomaticModesChangedEvent(12, 'Pedro', [], ['bot'], 'Admin', 9, '127.0.0.1', 'id@host', $time));
        self::assertSame(['+B'], $this->calls);
        $beforeClear = $this->history;
        self::assertCount(1, $beforeClear);
        $entry = $beforeClear[0];
        self::assertSame('SASET_MODES_SET', $entry->getAction());
        self::assertSame('Admin', $entry->getPerformedBy());
        self::assertSame(9, $entry->getPerformedByNickId());
        self::assertSame('history.message.modes_changed', $entry->getMessage());
        self::assertSame(['ip' => '127.0.0.1', 'host' => 'id@host', 'old_modes' => '', 'new_modes' => 'bot'], $entry->getExtraData());
        self::assertSame($time, $entry->getPerformedAt());
        $dispatcher->dispatch(new NickAutomaticModesChangedEvent(12, 'Offline', ['bot'], [], 'Admin', 9, '*', '*', $time));
        $afterClear = $this->history;
        self::assertCount(2, $afterClear);
        self::assertSame('SASET_MODES_CLEAR', $afterClear[1]->getAction());
        self::assertSame('history.message.modes_cleared', $afterClear[1]->getMessage());
    }

    public function testExplicitImplicitIdentificationAndIdentityLossUseOwnedDefaults(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch(new NickIdentifiedEvent(12, 'Pedro', 'UID', true));
        $dispatcher->dispatch(new UserDeidentifiedEvent('UID', 12, 'Pedro'));
        $dispatcher->dispatch(new NickIdentifiedEvent(12, 'Pedro', 'UID'));
        $dispatcher->dispatch(new UserModesChangedEvent('UID', '-B+B'));
        $dispatcher->dispatch(new UserDeidentifiedEvent('UID', 12, 'Pedro'));
        self::assertSame(['+B', '-B', '+B'], $this->calls);
    }

    public function testQuitUidReuseAndDisconnectForgetOwnershipWithoutSendingToLostSessions(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch(new NickIdentifiedEvent(12, 'Pedro', 'UID'));
        $dispatcher->dispatch(new UserLeftNetworkEvent('UID', 'Pedro', 'quit'));
        $dispatcher->dispatch(new UserDeidentifiedEvent('UID', 12, 'Pedro'));
        $dispatcher->dispatch(new NickIdentifiedEvent(12, 'Pedro', 'UID'));
        $dispatcher->dispatch(new NetworkConnectionLostEvent());
        $dispatcher->dispatch(new UserDeidentifiedEvent('UID', 12, 'Pedro'));
        self::assertSame(['+B', '+B'], $this->calls);
    }

    public function testAReintroducedUidCannotInheritOldOwnership(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch(new NickIdentifiedEvent(12, 'Pedro', 'UID'));
        $dispatcher->dispatch(new UserJoinedNetworkAppEvent(new UserJoinedNetworkDTO('UID', 'Pedro', 'id', 'host', 'cloak', '*', 'cloak')));
        $dispatcher->dispatch(new UserDeidentifiedEvent('UID', 12, 'Pedro'));
        self::assertSame(['+B'], $this->calls);
    }

    private function dispatcher(): EventDispatcher
    {
        $nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
        $nick->changeAutomaticModes(new AutomaticModeProfile(['bot']));
        $nicks = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nicks->method('findByNick')->willReturn($nick);
        $users = $this->createStub(NickNetworkUserLookup::class);
        $users->method('findByUid')->willReturn(new NetworkUser('UID', 'Pedro', 'id', 'host', 'cloak', '*', true));
        $identities = $this->createStub(IdentifiedSessionTracker::class);
        $identities->method('findNick')->willReturn('Pedro');
        $identities->method('findUidByNick')->willReturnCallback(static fn (string $nick): ?string => 'Pedro' === $nick ? 'UID' : null);
        $catalog = $this->createStub(AutomaticModeCatalog::class);
        $catalog->method('available')->willReturn(['bot' => 'B']);
        $actions = $this->createStub(AutomaticModeActions::class);
        $actions->method('change')->willReturnCallback(function (string $uid, string $delta): bool {
            $this->calls[] = $delta;

            return true;
        });
        $history = $this->createStub(NickHistoryRepositoryInterface::class);
        $history->method('save')->willReturnCallback(function (NickHistory $entry): void {
            $this->history[] = $entry;
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new NickAutomaticModesSubscriber(new AutomaticModeSessionService($identities, $nicks, $users, $catalog, $actions), $identities, new NickHistoryService($history)));

        return $dispatcher;
    }
}
