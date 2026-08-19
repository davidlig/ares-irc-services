<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function sprintf;

final readonly class UdbNickSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordMigrationStateInterface $migrationState,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickPasswordProvidedEvent::class => 'onPasswordProvided',
            NickDropEvent::class => 'onNickDrop',
            UdbSyncRequestedEvent::class => 'onSyncRequested',
            UdbRecordReceivedEvent::class => 'onRecordReceived',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s', $event->nickname));
    }

    public function onPasswordProvided(NickPasswordProvidedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->migrationState->markAsMigrated($event->nickname);

        $hash = hash('sha256', $event->plaintextPassword);
        $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::pass sha256:%s', $event->nickname, $hash));

        $nick = $this->nickRepository->findById($event->nickId);
        if (null !== $nick && null !== $nick->getVhost()) {
            $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $event->nickname, $nick->getVhost()));
        }
    }

    public function onSyncRequested(UdbSyncRequestedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        if ('N' === $event->block) {
            $this->connectionHolder->writeLine('DB * REQ N');
        }
    }

    public function onRecordReceived(UdbRecordReceivedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        // Example key: N::davidlig::pass
        $parts = explode('::', $event->key);
        if (count($parts) < 3 || 'N' !== $parts[0]) {
            return;
        }

        $nickname = $parts[1];

        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            // Delete inconsistencies
            $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s', $nickname));

            return;
        }

        $this->migrationState->markAsMigrated($nickname);
    }
}
