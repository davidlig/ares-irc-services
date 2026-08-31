<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\PasswordMigrationStateInterface;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Event\NickUnsuspendedEvent;
use App\Domain\NickServ\Event\NickVhostChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function hash;
use function sprintf;

/**
 * Projects NickServ SQL changes into the authoritative UDB N block.
 *
 * The UDB store is always updated (even while the link is down or another
 * protocol driver is active); only wire propagation is gated by the session
 * coordinator. Incoming N-block frames are NEVER imported back into SQL.
 */
final class UdbNickSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'N';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordMigrationStateInterface $migrationState,
        private OperIrcopRepositoryInterface $ircopRepository,
        private UdbRecordExporter $exporter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickPasswordProvidedEvent::class => 'onPasswordProvided',
            NickVhostChangedEvent::class => 'onVhostChanged',
            NickSuspendedEvent::class => 'onNickSuspended',
            NickUnsuspendedEvent::class => 'onNickUnsuspended',
            NickDropEvent::class => 'onNickDrop',
            OperRoleForcedVhostChangedEvent::class => 'onOperRoleForcedVhostChanged',
            OperIrcopChangedEvent::class => 'onOperIrcopChanged',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, $event->nickname);
    }

    public function onPasswordProvided(NickPasswordProvidedEvent $event): void
    {
        $this->migrationState->markAsMigrated($event->nickname);

        // UDB validates hash forms itself; the SQL hash may be a PHP-only
        // algorithm (e.g. bcrypt), so the accepted form is derived from the
        // provided plaintext with the UDB-compatible sha256 scheme.
        $hash = hash('sha256', $event->plaintextPassword);
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::pass', $event->nickname), sprintf('sha256:%s', $hash));

        if (null === $event->nickId) {
            return;
        }

        $nick = $this->nickRepository->findById($event->nickId);
        if (null === $nick) {
            return;
        }

        $vhost = $this->exporter->effectiveVhost($nick);
        if (null !== $vhost) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::vhost', $event->nickname), $vhost);
        }
    }

    public function onVhostChanged(NickVhostChangedEvent $event): void
    {
        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $this->writeVhost($event->nickname, $account);
    }

    public function onNickSuspended(NickSuspendedEvent $event): void
    {
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::suspended', $event->nickname), $event->reason);
    }

    public function onNickUnsuspended(NickUnsuspendedEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::suspended', $event->nickname));
    }

    public function onOperRoleForcedVhostChanged(OperRoleForcedVhostChangedEvent $event): void
    {
        foreach ($this->ircopRepository->findByRoleId($event->roleId) as $ircop) {
            $account = $this->nickRepository->findById($ircop->getNickId());
            if (null !== $account) {
                $this->writeVhost($account->getNickname(), $account);
            }
        }
    }

    public function onOperIrcopChanged(OperIrcopChangedEvent $event): void
    {
        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $this->writeVhost($event->nickname, $account);

        $path = sprintf('%s::oper', $event->nickname);
        $ircop = $this->ircopRepository->findByNickId($event->nickId);
        if (null === $ircop) {
            $this->recordWriter->delete(self::BLOCK, $path);

            return;
        }

        $this->recordWriter->insert(self::BLOCK, $path, $ircop->getRole()->getName());
    }

    private function writeVhost(string $nickname, RegisteredNick $account): void
    {
        $vhost = $this->exporter->effectiveVhost($account);
        if (null !== $vhost) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::vhost', $nickname), $vhost);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, sprintf('%s::vhost', $nickname));
    }
}
