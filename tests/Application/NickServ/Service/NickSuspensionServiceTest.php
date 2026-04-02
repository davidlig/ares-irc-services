<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
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

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserAccount');
        $notifier->expects(self::never())->method('forceNick');

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('TestNick is not connected'));

        $service = new NickSuspensionService(
            $userLookup,
            $notifier,
            $identifiedRegistry,
            'Guest-',
            $logger,
        );

        $service->enforceSuspension($account);
    }

    #[Test]
    public function enforceSuspensionWhenUserOnlineRenamesAndDeIdentifies(): void
    {
        $account = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $onlineUser = new SenderView('UID123', 'TestNick', 'user', 'host', 'server', 'ip', false, true, 'SID1', 'host', 'o', '');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByNick')
            ->with('TestNick')
            ->willReturn($onlineUser);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserAccount')
            ->with('UID123', '0');
        $notifier->expects(self::once())
            ->method('forceNick')
            ->with('UID123', self::stringStartsWith('Guest-'));

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('TestNick [UID123] is connected'));

        $service = new NickSuspensionService(
            $userLookup,
            $notifier,
            $identifiedRegistry,
            'Guest-',
            $logger,
        );

        $service->enforceSuspension($account);

        self::assertNull($identifiedRegistry->findNick('UID123'));
    }

    #[Test]
    public function enforceSuspensionUsesCustomGuestPrefix(): void
    {
        $account = RegisteredNick::createPending('MyNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $onlineUser = new SenderView('UID999', 'MyNick', 'user', 'host', 'server', 'ip', false, true, 'SID1', 'host', 'o', '');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByNick')
            ->with('MyNick')
            ->willReturn($onlineUser);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('setUserAccount')
            ->with('UID999', '0');
        $notifier->expects(self::once())
            ->method('forceNick')
            ->with('UID999', self::stringStartsWith('Custom-'));

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $service = new NickSuspensionService(
            $userLookup,
            $notifier,
            $identifiedRegistry,
            'Custom-',
        );

        $service->enforceSuspension($account);
    }

    #[Test]
    public function enforceSuspensionGeneratesUniqueGuestNicks(): void
    {
        $account = RegisteredNick::createPending('User1', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $onlineUser = new SenderView('UID1', 'User1', 'user', 'host', 'server', 'ip', false, true, 'SID1', 'host', 'o', '');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(2))
            ->method('findByNick')
            ->with('User1')
            ->willReturn($onlineUser);

        $capturedNicks = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('setUserAccount');
        $notifier->expects(self::exactly(2))
            ->method('forceNick')
            ->willReturnCallback(static function (string $uid, string $guestNick) use (&$capturedNicks): void {
                $capturedNicks[] = $guestNick;
            });

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $service = new NickSuspensionService(
            $userLookup,
            $notifier,
            $identifiedRegistry,
        );

        $service->enforceSuspension($account);
        $service->enforceSuspension($account);

        self::assertCount(2, $capturedNicks);
        self::assertNotSame($capturedNicks[0], $capturedNicks[1]);
        self::assertStringStartsWith('Guest-', $capturedNicks[0]);
        self::assertStringStartsWith('Guest-', $capturedNicks[1]);
    }
}
