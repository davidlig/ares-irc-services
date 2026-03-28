<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\OperServ\Subscriber\OperRoleModesSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(OperRoleModesSubscriber::class)]
final class OperRoleModesSubscriberTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
    }

    #[Test]
    public function getSubscribedEventsReturnsNickIdentifiedEvent(): void
    {
        self::assertSame(
            [NickIdentifiedEvent::class => ['onNickIdentified', 0]],
            OperRoleModesSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onNickIdentifiedWithNoRoleDoesNotApplyModes(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn(null);

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            new IdentifiedSessionRegistry(),
            $this->connectionHolder,
            new NullLogger(),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithRoleNoModesDoesNothing(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

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

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            new IdentifiedSessionRegistry(),
            $this->connectionHolder,
            new NullLogger(),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithRoleAndModesAppliesSvsmode(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['o', 's']);

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
            ->with('001', '001ABCD', '+os');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = new ActiveConnectionHolder();
        $connectionHolder->setProtocolModule($protocolModule);
        $this->injectServerSid($connectionHolder, '001');

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            new NullLogger(),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedDispatchesSetUserMode(): void
    {
        $event = new NickIdentifiedEvent(123, 'AdminNick', '002XYZ');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['o']);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(123)
            ->willReturn($ircop);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions
            ->expects(self::once())
            ->method('setUserMode')
            ->with(
                self::equalTo('003'),
                self::equalTo('002XYZ'),
                self::equalTo('+o'),
            );

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = new ActiveConnectionHolder();
        $connectionHolder->setProtocolModule($protocolModule);
        $this->injectServerSid($connectionHolder, '003');

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            new NullLogger(),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithNoProtocolModuleDoesNothing(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $role = $this->createStub(OperRole::class);
        $role->method('getUserModes')->willReturn(['o']);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn($ircop);

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            new IdentifiedSessionRegistry(),
            new ActiveConnectionHolder(),
            new NullLogger(),
        );

        $subscriber->onNickIdentified($event);
    }

    private function injectServerSid(ActiveConnectionHolder $holder, string $sid): void
    {
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('serverSid');
        $property->setAccessible(true);
        $property->setValue($holder, $sid);
    }
}
