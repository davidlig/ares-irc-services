<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\NickServ\Service\NickForceService;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SenderView;
use App\Application\Port\ServiceNickReservationInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ForbiddenNickService::class)]
final class ForbiddenNickServiceTest extends TestCase
{
    #[Test]
    public function forbidCreatesNewForbiddenNick(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick')
            ->with(self::anything(), self::anything(), 'BadNick', 'Spam');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            reservation: $reservation,
        );

        $result = $forbiddenService->forbid('BadNick', 'Spam', 'Admin');

        self::assertSame('BadNick', $result->getNickname());
        self::assertTrue($result->isForbidden());
        self::assertSame('Spam', $result->getReason());
    }

    #[Test]
    public function forbidDropsExistingAccountBeforeCreatingForbidden(): void
    {
        $existingNick = RegisteredNick::createPending('BadUser', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $existingNick->activate();

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($existingNick);
        $nickRepository->expects(self::once())->method('save');

        $forbiddenService = $this->createService(nickRepository: $nickRepository);

        $result = $forbiddenService->forbid('BadUser', 'Spam', 'Admin');

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function forbidForcesRenameIfUserOnline(): void
    {
        $onlineUser = new SenderView('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o', '');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'forbidden-nick');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::exactly(2))->method('reserveNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->forbid('BadNick', 'Spam', 'Admin');
    }

    #[Test]
    public function forbidAppliesNickReservation(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick')
            ->with(
                self::callback(static fn ($conn) => $conn instanceof ConnectionInterface),
                self::equalTo('001'),
                self::equalTo('BadNick'),
                self::equalTo('Spamming network'),
            );

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->forbid('BadNick', 'Spamming network', 'Admin');
    }

    #[Test]
    public function updateReasonUpdatesForbiddenNick(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Old reason');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick')
            ->with(self::anything(), self::anything(), 'BadNick', 'New reason');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->updateReason($nick, 'New reason');

        self::assertSame('New reason', $nick->getReason());
    }

    #[Test]
    public function updateReasonForcesRenameIfUserOnline(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Old reason');
        $onlineUser = new SenderView('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o', '');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::exactly(2))->method('reserveNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->updateReason($nick, 'New reason');
    }

    #[Test]
    public function unforbidReturnsTrueWhenForbidden(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('releaseNick')
            ->with(
                self::callback(static fn ($conn) => $conn instanceof ConnectionInterface),
                self::equalTo('001'),
                self::equalTo('BadNick'),
            );

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('BadNick');

        self::assertTrue($result);
    }

    #[Test]
    public function unforbidRemovesNickReservation(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('releaseNick')
            ->with(
                self::callback(static fn ($conn) => $conn instanceof ConnectionInterface),
                '001',
                'BadNick',
            );

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->unforbid('BadNick');
    }

    #[Test]
    public function unforbidReturnsFalseWhenNotForbidden(): void
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('SomeNick');

        self::assertFalse($result);
    }

    #[Test]
    public function unforbidReturnsFalseWhenRegisteredNotForbidden(): void
    {
        $nick = RegisteredNick::createPending('SomeNick', 'hash', 'email@test.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('SomeNick');

        self::assertFalse($result);
    }

    #[Test]
    public function notifyAndForceGuestFetchesNicknameFromUserLookupWhenNull(): void
    {
        $onlineUser = new SenderView('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o', '');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($onlineUser);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'protection.nick_forbidden',
            ['%nickname%' => 'BadNick', '%reason%' => 'Spam reason'],
            'nickserv',
            'en',
        )->willReturn('Nickname BadNick is forbidden: Spam reason');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID123', 'Nickname BadNick is forbidden: Spam reason', 'NOTICE');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'forbidden-nick');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick');

        $forbiddenService = $this->createService(
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            translator: $translator,
            reservation: $reservation,
        );

        $forbiddenService->notifyAndForceGuest('UID123', 'Spam reason', null);
    }

    #[Test]
    public function notifyAndForceGuestUsesUnknownWhenUserNotFound(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'protection.nick_forbidden',
            ['%nickname%' => 'Unknown', '%reason%' => 'Spam reason'],
            'nickserv',
            'en',
        )->willReturn('Nickname Unknown is forbidden: Spam reason');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID123', 'Nickname Unknown is forbidden: Spam reason', 'NOTICE');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::once())->method('reserveNick');

        $forbiddenService = $this->createService(
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            translator: $translator,
            reservation: $reservation,
        );

        $forbiddenService->notifyAndForceGuest('UID123', 'Spam reason', null);
    }

    #[Test]
    public function forbidSkipsNickReservationWhenNoProtocolModule(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            hasProtocolModule: false,
        );

        $result = $forbiddenService->forbid('BadNick', 'Spam', 'Admin');

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function forbidSkipsNickReservationWhenProtocolDoesNotSupportIt(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getNickReservation')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);

        $forbiddenService = new ForbiddenNickService(
            $nickRepository,
            $this->createStub(NickForceService::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $connectionHolder,
            $this->createStub(LoggerInterface::class),
            'en',
        );

        $result = $forbiddenService->forbid('BadNick', 'Spam', 'Admin');

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function forbidSkipsNickReservationWhenNoConnection(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('reserveNick');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getNickReservation')->willReturn($reservation);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getConnection')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $forbiddenService = new ForbiddenNickService(
            $nickRepository,
            $this->createStub(NickForceService::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $connectionHolder,
            $this->createStub(LoggerInterface::class),
            'en',
        );

        $result = $forbiddenService->forbid('BadNick', 'Spam', 'Admin');

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function unforbidSkipsNickReservationWhenNoProtocolModule(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            hasProtocolModule: false,
        );

        $result = $forbiddenService->unforbid('BadNick');

        self::assertTrue($result);
    }

    #[Test]
    public function unforbidSkipsNickReservationWhenProtocolDoesNotSupportIt(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getNickReservation')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);

        $forbiddenService = new ForbiddenNickService(
            $nickRepository,
            $this->createStub(NickForceService::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $connectionHolder,
            $this->createStub(LoggerInterface::class),
            'en',
        );

        $result = $forbiddenService->unforbid('BadNick');

        self::assertTrue($result);
    }

    #[Test]
    public function unforbidSkipsNickReservationWhenNoConnection(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getNickReservation')->willReturn($reservation);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getConnection')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $forbiddenService = new ForbiddenNickService(
            $nickRepository,
            $this->createStub(NickForceService::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $connectionHolder,
            $this->createStub(LoggerInterface::class),
            'en',
        );

        $result = $forbiddenService->unforbid('BadNick');

        self::assertTrue($result);
    }

    private function createService(
        ?RegisteredNickRepositoryInterface $nickRepository = null,
        ?NickForceService $forceService = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?NickServNotifierInterface $notifier = null,
        ?TranslatorInterface $translator = null,
        ?ServiceNickReservationInterface $reservation = null,
        bool $hasProtocolModule = true,
    ): ForbiddenNickService {
        $connection = $this->createStub(ConnectionInterface::class);
        $protocolModule = null;

        if ($hasProtocolModule) {
            $reservationMock = $reservation ?? $this->createStub(ServiceNickReservationInterface::class);
            $protocolModule = $this->createStub(ProtocolModuleInterface::class);
            $protocolModule->method('getNickReservation')->willReturn($reservationMock);
        }

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getConnection')->willReturn($connection);
        $connectionHolder->method('getServerSid')->willReturn('001');
        $connectionHolder->method('isConnected')->willReturn(true);

        return new ForbiddenNickService(
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $forceService ?? $this->createStub(NickForceService::class),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $notifier ?? $this->createStub(NickServNotifierInterface::class),
            $translator ?? $this->createStub(TranslatorInterface::class),
            $connectionHolder,
            $this->createStub(LoggerInterface::class),
            'en',
        );
    }
}
