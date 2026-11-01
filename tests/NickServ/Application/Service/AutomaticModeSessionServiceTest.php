<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\AutomaticModeActions;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\AutomaticModeSessionService;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomaticModeSessionService::class)]
final class AutomaticModeSessionServiceTest extends TestCase
{
    private ?RegisteredNick $nick;

    private ?NetworkUser $user;

    private ?string $identity = 'Pedro';

    /** @var array<string,string>|null */
    private ?array $available = ['bot' => 'B', 'invisible' => 'i'];

    private bool $native = false;

    private bool $delivered = true;

    /** @var list<string> */
    private array $calls = [];

    private AutomaticModeSessionService $service;

    public function testReplacingAnOwnedMeaningWithAnotherOnTheSameLetterStillAppliesTheNewDefault(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('Bi');
        self::assertNotNull($this->nick);
        $this->nick->changeAutomaticModes(new AutomaticModeProfile(['strip-formatting']));
        $this->available = ['strip-formatting' => 'B'];
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        self::assertSame(['+B', '-B', '+B', '-B'], $this->calls);
    }

    public function testOnlyOwnsNewBitsAndRevokesThemOnIdentityLoss(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        $this->service->remove('001USER');
        self::assertSame(['+B', '-B'], $this->calls);
    }

    public function testRemappingRevokesOldOwnedLetterAndOwnsTheNewLetter(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('Bi');
        $this->available = ['bot' => 'b', 'invisible' => 'i'];
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        self::assertSame(['+B', '-B', '+b', '-b'], $this->calls);
    }

    public function testManualRemovalRelinquishesOwnershipEvenWhenTheUserAddsTheModeAgain(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->service->observe('001USER', '-B+B');
        $this->service->remove('001USER');
        self::assertSame(['+B'], $this->calls);
        $this->service->observe('unknown', '-B');
    }

    public function testReplacementAndClearOnlyRemoveOwnedBits(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('Bi');
        self::assertNotNull($this->nick);
        $this->nick->changeAutomaticModes(new AutomaticModeProfile(['invisible']));
        $this->service->apply('001USER', 'Pedro');
        $this->nick->changeAutomaticModes(new AutomaticModeProfile());
        $this->service->apply('001USER', 'Pedro');
        self::assertSame(['+B', '-B'], $this->calls);
    }

    public function testUnknownSupportDoesNotApplyAndDoesNotPreventClearOfOwnedModes(): void
    {
        $this->available = null;
        $this->service->apply('001USER', 'Pedro');
        $this->available = ['bot' => 'B'];
        $this->service->apply('001USER', 'Pedro');
        $this->available = null;
        self::assertNotNull($this->nick);
        $this->nick->changeAutomaticModes(new AutomaticModeProfile());
        $this->service->apply('001USER', 'Pedro');
        self::assertSame(['+B', '-B'], $this->calls);
    }

    public function testFailedAddDoesNotAcquireOwnershipAndFailedRemovalCanBeRetried(): void
    {
        $this->delivered = false;
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        $this->delivered = true;
        $this->service->apply('001USER', 'Pedro');
        $this->delivered = false;
        self::assertNotNull($this->nick);
        $this->nick->changeAutomaticModes(new AutomaticModeProfile());
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        $this->delivered = true;
        $this->service->remove('001USER');
        self::assertSame(['+B', '+B', '-B', '-B', '-B'], $this->calls);
    }

    public function testForgettingOneUserAndAllUsersNeverInfersOwnershipFromExistingBits(): void
    {
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('Bi');
        $this->service->forget('001USER');
        $this->service->apply('001USER', 'Pedro');
        $this->service->remove('001USER');
        $this->service->forget();
        self::assertSame(['+B'], $this->calls);
    }

    public function testIgnoresNativeProfilesUnauthenticatedWrongIdentityMissingUsersAndOperators(): void
    {
        $this->native = true;
        $this->service->apply('001USER', 'Pedro');
        $this->native = false;
        $this->identity = null;
        $this->service->apply('001USER', 'Pedro');
        $this->identity = 'Other';
        $this->service->apply('001USER', 'Pedro');
        $this->identity = 'Pedro';
        $this->user = null;
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('i', false);
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('i', oper: true);
        $this->service->apply('001USER', 'Pedro');
        $this->user = $this->user('i');
        $this->nick = null;
        $this->service->apply('001USER', 'Pedro');
        self::assertSame([], $this->calls);
    }

    protected function setUp(): void
    {
        $this->nick = RegisteredNick::createActive('Pedro', 'hash', null, 'en', new DateTimeImmutable());
        $this->nick->changeAutomaticModes(new AutomaticModeProfile(['bot', 'invisible']));
        $this->user = $this->user('i');
        $nicks = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nicks->method('findByNick')->willReturnCallback(fn () => $this->nick);
        $sessions = $this->createStub(IdentifiedSessionTracker::class);
        $sessions->method('findNick')->willReturnCallback(fn () => $this->identity);
        $users = $this->createStub(NickNetworkUserLookup::class);
        $users->method('findByUid')->willReturnCallback(fn () => $this->user);
        $catalog = $this->createStub(AutomaticModeCatalog::class);
        $catalog->method('available')->willReturnCallback(fn () => $this->available);
        $actions = $this->createStub(AutomaticModeActions::class);
        $actions->method('usesNativeProfiles')->willReturnCallback(fn () => $this->native);
        $actions->method('change')
            ->willReturnCallback(function (string $uid, string $delta): bool {
                $this->calls[] = $delta;

                return $this->delivered;
            });
        $this->service = new AutomaticModeSessionService($sessions, $nicks, $users, $catalog, $actions);
    }

    private function user(string $modes, bool $identified = true, bool $oper = false): NetworkUser
    {
        return new NetworkUser('001USER', 'Pedro', 'id', 'host', 'cloak', '*', $identified, $oper, modes: $modes);
    }
}
