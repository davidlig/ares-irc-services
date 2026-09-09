<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\OperServ\Adapter\In\Event\IrcopModeApplier;
use App\OperServ\Adapter\In\Event\IrcopOperclassApplier;
use App\OperServ\Adapter\In\Event\OperRoleModesSubscriber;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
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
            new IrcopOperclassApplier(new IdentifiedSessionRegistry(), $this->createStub(ActiveProtocolModuleHolderInterface::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(NickProjectionQuery::class)),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithRoleNoModesDoesNothing(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $role = OperRole::create('ADMIN');
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role);

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
            new IrcopOperclassApplier(new IdentifiedSessionRegistry(), $this->createStub(ActiveProtocolModuleHolderInterface::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(NickProjectionQuery::class)),
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedWithRoleAndModesAppliesModes(): void
    {
        $event = new NickIdentifiedEvent(42, 'TestNick', '001ABCD');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['o', 's']);

        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role, null, null);

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
            ->with('001', '001ABCD', '+os', []);

        $userModeSupport = $this->createStub(UserModeSupportInterface::class);
        $userModeSupport->method('buildModeParams')->willReturnCallback(self::buildModeParams(...));

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);
        $protocolModule->method('getUserModeSupport')->willReturn($userModeSupport);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
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

        $nickRepo = $this->createStub(NickProjectionQuery::class);
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
            new IrcopOperclassApplier(new IdentifiedSessionRegistry(), $this->createStub(ActiveProtocolModuleHolderInterface::class), $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(NickProjectionQuery::class)),
        );

        $subscriber->onNickIdentified($event);
    }

    private function createModeApplier(): IrcopModeApplier
    {
        return new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(NickProjectionQuery::class),
            $this->createStub(NetworkUserLookupPort::class),
            new NullLogger(),
        );
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
}
