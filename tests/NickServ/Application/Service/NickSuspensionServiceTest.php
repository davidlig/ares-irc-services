<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Application\Service\NickSuspensionService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(NickSuspensionService::class)]
final class NickSuspensionServiceTest extends TestCase
{
    #[Test]
    public function enforceSuspensionWhenUserNotOnlineDoesNothing(): void
    {
        $account = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByNick')
            ->with('TestNick')
            ->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('TestNick is not connected'));

        $service = new NickSuspensionService(
            $userLookup,
            $forceService,
            'Guest-',
            $logger,
        );

        $service->enforceSuspension($account);
    }

    #[Test]
    public function enforceSuspensionWhenUserOnlineCallsForceService(): void
    {
        $account = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $onlineUser = new SenderView('UID123', 'TestNick', 'user', 'host', 'server', 'ip', false, true, 'SID1', 'host', 'o');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByNick')
            ->with('TestNick')
            ->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())
            ->method('forceGuestNick')
            ->with('UID123', null, 'suspension');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('TestNick [UID123] is connected'));

        $service = new NickSuspensionService(
            $userLookup,
            $forceService,
            'Guest-',
            $logger,
        );

        $service->enforceSuspension($account);
    }

    #[Test]
    public function exposesGuestPrefix(): void
    {
        $service = new NickSuspensionService(
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            'Guest-',
        );

        self::assertSame('Guest-', $service->getGuestPrefix());
    }
}
