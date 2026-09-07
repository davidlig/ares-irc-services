<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(IdentifiedUserVhostSyncService::class)]
final class IdentifiedUserVhostSyncServiceTest extends TestCase
{
    private function createService(
        RegisteredNickRepositoryInterface $nickRepo,
        NickNetworkActions $notifier,
        ?VhostDisplayResolver $resolver = null,
        ?ForcedVhostCheckerInterface $forcedVhostChecker = null,
        ?NickServActivitySink $logger = null,
        ?NickChangeIdentificationPolicy $connectionHolder = null,
    ): IdentifiedUserVhostSyncService {
        return new IdentifiedUserVhostSyncService(
            $nickRepo,
            $notifier,
            $resolver ?? new VhostDisplayResolver(),
            $forcedVhostChecker ?? $this->createStub(ForcedVhostCheckerInterface::class),
            $connectionHolder ?? $this->createStub(NickChangeIdentificationPolicy::class),
            $logger ?? $this->createStub(NickServActivitySink::class),
        );
    }

    private function createAccountWithId(string $nick): RegisteredNick
    {
        $account = RegisteredNick::createPending($nick, 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $account->activate();

        $refl = new ReflectionClass($account);
        $idProp = $refl->getProperty('id');
        $idProp->setValue($account, 1);

        return $account;
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedAndVhostActive(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostEvenWhenDisplayHostEqualsCloakedHost(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Cloak123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenIdentifiedButNoAccount(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn(null);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenAccountHasNoVhost(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesVhostWhenIdentifiedWithAccountVhost(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);
        $ircopRepo = $this->createStub(ForcedVhostCheckerInterface::class);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserUsesDisplayResolverSuffix(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost.virtual', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier, new VhostDisplayResolver('virtual'));
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenAccountNotRegistered(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = RegisteredNick::createPending(
            'Nick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenVhostEmptyAfterDisplayResolution(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserLogsWhenVhostApplied(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);
        $logger = $this->createMock(NickServActivitySink::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('IdentifiedUserVhostSync'));

        $service = $this->createService($repo, $notifier, null, null, $logger);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesForcedVhostWhenIrcopHasRoleWithPattern(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'davidlig.admin.network', 'SID');

        $user = new NetworkUser('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircopRepo = $this->createMock(ForcedVhostCheckerInterface::class);
        $ircopRepo->expects(self::once())->method('resolveForcedVhost')->with(1, 'davidlig')->willReturn('davidlig.admin.network');

        $logger = $this->createMock(NickServActivitySink::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('forced vhost applied'));

        $service = $this->createService($repo, $notifier, null, $ircopRepo, $logger);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesPersonalVhostWhenIrcopHasNoForcedVhost(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'personal.vhost', 'SID');

        $user = new NetworkUser('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircopRepo = $this->createMock(ForcedVhostCheckerInterface::class);
        $ircopRepo->expects(self::once())->method('resolveForcedVhost')->with(1, 'davidlig')->willReturn(null);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesPersonalVhostWhenNotIrcop(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'personal.vhost', 'SID');

        $user = new NetworkUser('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircopRepo = $this->createMock(ForcedVhostCheckerInterface::class);
        $ircopRepo->expects(self::once())->method('resolveForcedVhost')->with(1, 'davidlig')->willReturn(null);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedEvenIfAccountHasVhost(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');

        $user = new NetworkUser('UID1', 'davidlig', 'i', 'h', 'c', 'ip', false, false, 'SID');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNotClearVhostWhenNotIdentifiedAndProtocolHandlesVhostServerSide(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $module = $this->createStub(IdentifiedUserVhostSyncTestProtocolModule::class);
        $connectionHolder = $this->createStub(NickChangeIdentificationPolicy::class);
        $connectionHolder->method('preservesIdentification')->willReturn(true);

        $service = $this->createService($repo, $notifier, connectionHolder: $connectionHolder);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedAndProtocolDoesNotHandleVhostServerSide(): void
    {
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new NetworkUser('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $module = $this->createStub(IdentifiedUserVhostSyncTestStandardProtocolModule::class);
        $connectionHolder = $this->createStub(NickChangeIdentificationPolicy::class);
        $connectionHolder->method('preservesIdentification')->willReturn(false);

        $service = $this->createService($repo, $notifier, connectionHolder: $connectionHolder);
        $service->syncVhostForUser($user);
    }
}

interface IdentifiedUserVhostSyncTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
interface IdentifiedUserVhostSyncTestStandardProtocolModule extends ProtocolModuleInterface {}
