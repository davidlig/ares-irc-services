<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\OperRoleModesSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperRoleModesSubscriber::class)]
final class OperRoleModesSubscriberTest extends TestCase
{
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

        $modeApplier = $this->createModeApplier();

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            $modeApplier,
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

        $modeApplier = $this->createModeApplier();

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            $modeApplier,
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithRoleAndModesAppliesModes(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['o', 's']);

        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository
            ->expects(self::once())
            ->method('findByNickId')
            ->with(42)
            ->willReturn($ircop);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('001ABCD', 'TestNick');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions
            ->expects(self::once())
            ->method('setUserMode')
            ->with('001', '001ABCD', '+os');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: '001ABCD',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+i',
        ));

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);

        $modeApplier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $userLookup,
            new NullLogger(),
        );

        $subscriber = new OperRoleModesSubscriber(
            $ircopRepository,
            $modeApplier,
        );

        $subscriber->onNickIdentified($event);
    }

    private function createModeApplier(): IrcopModeApplier
    {
        return new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $this->createStub(ActiveConnectionHolderInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new NullLogger(),
        );
    }
}
