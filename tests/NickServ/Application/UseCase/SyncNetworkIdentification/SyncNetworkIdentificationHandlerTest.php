<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\SyncNetworkIdentification;

use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentification;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandler;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(SyncNetworkIdentification::class)]
#[CoversClass(SyncNetworkIdentificationHandler::class)]
final class SyncNetworkIdentificationHandlerTest extends TestCase
{
    #[Test]
    public function establishesIdentificationFromTheFinalNetworkStateOnlyOnce(): void
    {
        $account = $this->registeredNick('MyNick', 42);
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('MyNick')->willReturn($account);
        $repository->expects(self::once())->method('save')->with(self::identicalTo($account));
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (NickIdentifiedEvent $event): bool => 42 === $event->nickId
                && 'MyNick' === $event->nickname
                && '001ABC' === $event->uid
                && $event->implicit,
        ));
        $identifiedSessions = new IdentifiedSessionRegistry();
        $handler = $this->handler($repository, $events, $identifiedSessions, new SessionLanguageRegistry());
        $command = new SyncNetworkIdentification(
            new NetworkUser('001ABC', 'MyNick', 'ident', 'host', 'cloak', 'ip', isIdentified: true),
            new DateTimeImmutable('2026-09-15 20:00:00'),
        );

        $handler->handle($command);
        $handler->handle($command);

        self::assertSame('MyNick', $identifiedSessions->findNick('001ABC'));
    }

    #[Test]
    public function replacesAStaleIdentificationWhenTheNetworkConfirmsAnotherAccount(): void
    {
        $oldAccount = $this->registeredNick('OldNick', 41);
        $newAccount = $this->registeredNick('NewNick', 42);
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByNick')->willReturnCallback(
            static fn (string $nickname): ?RegisteredNick => match ($nickname) {
                'OldNick' => $oldAccount,
                'NewNick' => $newAccount,
                default => null,
            },
        );
        $repository->expects(self::once())->method('save')->with(self::identicalTo($newAccount));

        /** @var list<object> $published */
        $published = [];
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function (object $event) use (&$published): void {
                $published[] = $event;
            },
        );

        $identifiedSessions = new IdentifiedSessionRegistry();
        $identifiedSessions->register('001ABC', 'OldNick');
        $sessionLanguages = new SessionLanguageRegistry();
        $sessionLanguages->register('001ABC', 'es');
        $handler = $this->handler($repository, $events, $identifiedSessions, $sessionLanguages);
        $command = new SyncNetworkIdentification(
            new NetworkUser('001ABC', 'NewNick', 'ident', 'host', 'cloak', 'ip', isIdentified: true),
            new DateTimeImmutable('2026-09-15 20:00:00'),
        );

        $handler->handle($command);
        $handler->handle($command);

        self::assertCount(2, $published);
        self::assertInstanceOf(UserDeidentifiedEvent::class, $published[0]);
        self::assertSame(41, $published[0]->nickId);
        self::assertSame('OldNick', $published[0]->nickname);
        self::assertInstanceOf(NickIdentifiedEvent::class, $published[1]);
        self::assertSame(42, $published[1]->nickId);
        self::assertSame('NewNick', $published[1]->nickname);
        self::assertTrue($published[1]->implicit);
        self::assertSame('NewNick', $identifiedSessions->findNick('001ABC'));
        self::assertNull($sessionLanguages->find('001ABC'));
    }

    #[Test]
    public function removesIdentificationAndLanguageAndPublishesDeidentificationOnlyOnce(): void
    {
        $account = $this->registeredNick('MyNick', 42);
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('MyNick')->willReturn($account);
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (UserDeidentifiedEvent $event): bool => 42 === $event->nickId
                && 'MyNick' === $event->nickname
                && '001ABC' === $event->uid,
        ));
        $identifiedSessions = new IdentifiedSessionRegistry();
        $identifiedSessions->register('001ABC', 'MyNick');
        $sessionLanguages = new SessionLanguageRegistry();
        $sessionLanguages->register('001ABC', 'es');
        $handler = $this->handler($repository, $events, $identifiedSessions, $sessionLanguages);
        $command = new SyncNetworkIdentification(
            new NetworkUser('001ABC', 'OtherNick', 'ident', 'host', 'cloak', 'ip', isIdentified: false),
            new DateTimeImmutable('2026-09-15 20:00:00'),
        );

        $handler->handle($command);
        $handler->handle($command);

        self::assertNull($identifiedSessions->findNick('001ABC'));
        self::assertNull($sessionLanguages->find('001ABC'));
    }

    #[Test]
    public function removesAStaleSessionEvenWhenItsAccountNoLongerExists(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('MissingNick')->willReturn(null);
        $events = $this->createMock(NickServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $identifiedSessions = new IdentifiedSessionRegistry();
        $identifiedSessions->register('001ABC', 'MissingNick');
        $handler = $this->handler($repository, $events, $identifiedSessions, new SessionLanguageRegistry());

        $handler->handle(new SyncNetworkIdentification(
            new NetworkUser('001ABC', 'OtherNick', 'ident', 'host', 'cloak', 'ip'),
            new DateTimeImmutable('2026-09-15 20:00:00'),
        ));

        self::assertNull($identifiedSessions->findNick('001ABC'));
    }

    private function handler(
        RegisteredNickRepositoryInterface $repository,
        NickServEventPublisher $events,
        IdentifiedSessionRegistry $identifiedSessions,
        SessionLanguageRegistry $sessionLanguages,
    ): SyncNetworkIdentificationHandler {
        $protection = new NickProtectionService(
            $repository,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedSessions,
            $sessionLanguages,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $events,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        return new SyncNetworkIdentificationHandler(
            $protection,
            $identifiedSessions,
            $sessionLanguages,
            $repository,
            $events,
        );
    }

    private function registeredNick(string $nickname, int $id): RegisteredNick
    {
        $account = RegisteredNick::createPending(
            $nickname,
            'hash',
            'user@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );
        $account->activate();

        $reflection = new ReflectionClass($account);
        $reflection->getProperty('id')->setValue($account, $id);

        return $account;
    }

    private function guestNicknameGenerator(): GuestNicknameGenerator
    {
        $generator = $this->createStub(GuestNicknameGenerator::class);
        $generator->method('generate')->willReturn('Guest-ABC1234');

        return $generator;
    }
}
