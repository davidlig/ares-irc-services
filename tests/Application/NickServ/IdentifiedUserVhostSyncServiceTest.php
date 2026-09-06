<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\SenderView;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[CoversClass(IdentifiedUserVhostSyncService::class)]
final class IdentifiedUserVhostSyncServiceTest extends TestCase
{
    private function createService(
        RegisteredNickRepositoryInterface $nickRepo,
        NickServNotifierInterface $notifier,
        ?VhostDisplayResolver $resolver = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?LoggerInterface $logger = null,
        ?ActiveConnectionHolderInterface $connectionHolder = null,
    ): IdentifiedUserVhostSyncService {
        return new IdentifiedUserVhostSyncService(
            $nickRepo,
            $notifier,
            $resolver ?? new VhostDisplayResolver(),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function createAccountWithId(string $nick): RegisteredNick
    {
        $account = RegisteredNick::createPending($nick, 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $refl = new ReflectionClass($account);
        $idProp = $refl->getProperty('id');
        $idProp->setValue($account, 1);

        return $account;
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedAndVhostActive(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostEvenWhenDisplayHostEqualsCloakedHost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Cloak123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenIdentifiedButNoAccount(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn(null);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenAccountHasNoVhost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier);
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
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
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
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = RegisteredNick::createPending('Nick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNothingWhenVhostEmptyAfterDisplayResolution(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
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
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'my-vhost', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('Nick');
        $account->changeVhost('my-vhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('Nick')->willReturn($account);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('IdentifiedUserVhostSync'));

        $service = $this->createService($repo, $notifier, null, null, $logger);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesForcedVhostWhenIrcopHasRoleWithPattern(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'davidlig.admin.network', 'SID');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->changeForcedVhostPattern('admin.network');
        $ircop = OperIrcop::create(1, $role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('forced vhost applied'));

        $service = $this->createService($repo, $notifier, null, $ircopRepo, $logger);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesPersonalVhostWhenIrcopHasNoForcedVhost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'personal.vhost', 'SID');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(1, $role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserAppliesPersonalVhostWhenNotIrcop(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', 'personal.vhost', 'SID');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip', true, false, 'SID');
        $account = $this->createAccountWithId('davidlig');
        $account->changeVhost('personal.vhost');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn(null);

        $service = $this->createService($repo, $notifier, null, $ircopRepo);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedEvenIfAccountHasVhost(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip', false, false, 'SID');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $service = $this->createService($repo, $notifier);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserDoesNotClearVhostWhenNotIdentifiedAndProtocolHandlesVhostServerSide(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $module = $this->createStub(IdentifiedUserVhostSyncTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $service = $this->createService($repo, $notifier, connectionHolder: $connectionHolder);
        $service->syncVhostForUser($user);
    }

    #[Test]
    public function syncVhostForUserClearsVhostWhenNotIdentifiedAndProtocolDoesNotHandleVhostServerSide(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('UID1', '', 'SID');
        $user = new SenderView('UID1', 'Nick', 'i', 'h', 'Cloak123', 'ip', false, false, 'SID', 'Vhost123');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $module = $this->createStub(IdentifiedUserVhostSyncTestStandardProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $service = $this->createService($repo, $notifier, connectionHolder: $connectionHolder);
        $service->syncVhostForUser($user);
    }
}

interface IdentifiedUserVhostSyncTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
interface IdentifiedUserVhostSyncTestStandardProtocolModule extends ProtocolModuleInterface {}
