<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\OperServ\Adapter\In\Event\OperRoleModesDeidentifiedSubscriber;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(OperRoleModesDeidentifiedSubscriber::class)]
final class OperRoleModesDeidentifiedSubscriberTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
    }

    #[Test]
    public function getSubscribedEventsReturnsUserDeidentifiedEvent(): void
    {
        self::assertSame(
            [UserDeidentifiedEvent::class => ['onUserDeidentified', 0]],
            OperRoleModesDeidentifiedSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onUserDeidentifiedWithNoRoleDoesNotApplyModes(): void
    {
        $event = new UserDeidentifiedEvent('UID123', 42, 'TestNick');

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn(null);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('applyModeChange');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
            $userLookup,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    #[Test]
    public function onUserDeidentifiedWithRoleNoModesDoesNothing(): void
    {
        $event = new UserDeidentifiedEvent('UID123', 42, 'TestNick');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn([]);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn($ircop);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('applyModeChange');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
            $userLookup,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    #[Test]
    public function onUserDeidentifiedWithNoProtocolModuleDoesNothing(): void
    {
        $event = new UserDeidentifiedEvent('UID123', 42, 'TestNick');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['H', 'W']);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn($ircop);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('applyModeChange');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
            $userLookup,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    #[Test]
    public function onUserDeidentifiedWithNoServerSidDoesNothing(): void
    {
        $event = new UserDeidentifiedEvent('UID123', 42, 'TestNick');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['H']);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $connectionHolder = new ActiveConnectionHolder();
        $connectionHolder->setProtocolModule($module);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('applyModeChange');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $connectionHolder,
            $userLookup,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    #[Test]
    public function onUserDeidentifiedWithRoleAndModesAppliesNegativeSvsmode(): void
    {
        $event = new UserDeidentifiedEvent('001ABC', 42, 'TestNick');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['H', 'q']);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn($ircop);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions
            ->expects(self::once())
            ->method('setUserMode')
            ->with('001', '001ABC', '-Hq', []);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getUserModeSupport')->willReturn($this->createModeSupportStub());

        $connectionHolder = new ActiveConnectionHolder();
        $connectionHolder->setProtocolModule($module);
        $this->injectServerSid($connectionHolder, '001');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup
            ->expects(self::once())
            ->method('applyModeChange')
            ->with('001ABC', '-Hq');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $connectionHolder,
            $userLookup,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    private function createModeSupportStub(): UserModeSupportInterface
    {
        $support = $this->createStub(UserModeSupportInterface::class);
        $support->method('buildModeParams')->willReturnCallback(self::buildModeParams(...));

        return $support;
    }

    /**
     * @param array<int, string> $modes
     *
     * @return array{string, list<string>}
     */
    private static function buildModeParams(string $sign, array $modes): array
    {
        return [$sign . implode('', $modes), []];
    }

    private function injectServerSid(ActiveConnectionHolder $holder, string $sid): void
    {
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('serverSid');
        $property->setValue($holder, $sid);
    }
}
