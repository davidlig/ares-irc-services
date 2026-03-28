<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\OperServ\Subscriber\OperRoleModesDeidentifiedSubscriber;
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

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
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

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
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

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $this->connectionHolder,
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
            ->with('001', '001ABC', '-Hq');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = new ActiveConnectionHolder();
        $connectionHolder->setProtocolModule($module);
        $this->injectServerSid($connectionHolder, '001');

        $subscriber = new OperRoleModesDeidentifiedSubscriber(
            $ircopRepository,
            $connectionHolder,
            new NullLogger(),
        );

        $subscriber->onUserDeidentified($event);
    }

    private function injectServerSid(ActiveConnectionHolder $holder, string $sid): void
    {
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('serverSid');
        $property->setAccessible(true);
        $property->setValue($holder, $sid);
    }
}
