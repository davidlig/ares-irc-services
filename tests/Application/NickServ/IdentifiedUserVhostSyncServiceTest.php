<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifiedUserVhostSyncService::class)]
final class IdentifiedUserVhostSyncServiceTest extends TestCase
{
    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentified(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false, 'SID');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = new IdentifiedUserVhostSyncService($repo, $notifier, new VhostDisplayResolver());
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenIdentifiedButNoAccount(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('Nick')->willReturn(null);

        $service = new IdentifiedUserVhostSyncService($repo, $notifier, new VhostDisplayResolver());
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenAccountHasNoVhost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = RegisteredNick::createPending('Nick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('Nick')->willReturn($account);

        $service = new IdentifiedUserVhostSyncService($repo, $notifier, new VhostDisplayResolver());
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesVhostWhenIdentifiedWithAccountVhost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = RegisteredNick::createPending('Nick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('Nick')->willReturn($account);

        $service = new IdentifiedUserVhostSyncService($repo, $notifier, new VhostDisplayResolver());
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserUsesDisplayResolverSuffix(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost.virtual', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = RegisteredNick::createPending('Nick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('Nick')->willReturn($account);

        $service = new IdentifiedUserVhostSyncService($repo, $notifier, new VhostDisplayResolver('virtual'));
        $service->syncVhostForUser($user);
    }
}
