<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\OperServ\Application\PublishedEvent\OperIrcopChangedEvent;
use App\OperServ\Application\PublishedEvent\OperRoleForcedVhostChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
        private NickProjectionQuery $nicks,
        private PasswordMigrationStateInterface $migrationState,
        private OperatorNetworkProjectionQuery $operators,
        private UdbRecordExporter $exporter,
        private ?UdbSessionStateInterface $sessionState = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickPasswordHashAvailable::class => 'onPasswordHashAvailable',
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

    public function onPasswordHashAvailable(NickPasswordHashAvailable $event): void
    {
        $this->migrationState->markAsMigrated($event->nickname);

        // Re-project the SQL hash (the event carries it) instead of deriving a
        // second hash: both stores must verify the same password.
        $udbHash = $this->exporter->toUdbPasswordHash($event->passwordHash);
        if (null !== $udbHash) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::pass', $event->nickname), $udbHash);
        }

        if (null === $event->nickId) {
            return;
        }

        $nick = $this->nicks->findById($event->nickId);
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
        $account = $this->nicks->findById($event->nickId);
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
        foreach ($this->operators->findNickIdsByRoleId($event->roleId) as $nickId) {
            $account = $this->nicks->findById($nickId);
            if (null !== $account) {
                $this->writeVhost($account->nickname, $account);
            }
        }
    }

    public function onOperIrcopChanged(OperIrcopChangedEvent $event): void
    {
        $account = $this->nicks->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $this->writeVhost($event->nickname, $account);

        $path = sprintf('%s::oper', $event->nickname);
        $operator = $this->operators->findForNick($event->nickId, $event->nickname);
        if (null === $operator) {
            $this->recordWriter->delete(self::BLOCK, $path);

            return;
        }

        $operclass = $operator->operclass;
        if (null === $operclass || '' === $operclass || (null !== $this->sessionState && !$this->sessionState->isOperclassGloballyAvailable($operclass))) {
            $this->recordWriter->delete(self::BLOCK, $path);

            return;
        }

        $this->recordWriter->insert(self::BLOCK, $path, $operclass);
    }

    private function writeVhost(string $nickname, NickProjection $account): void
    {
        $vhost = $this->exporter->effectiveVhost($account);
        if (null !== $vhost) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::vhost', $nickname), $vhost);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, sprintf('%s::vhost', $nickname));
    }
}
