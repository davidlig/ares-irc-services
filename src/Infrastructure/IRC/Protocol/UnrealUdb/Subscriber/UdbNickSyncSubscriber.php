<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Event\NickVhostChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function explode;
use function hash;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final class UdbNickSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'N';

    /** @var array<string, true> */
    private array $receivedKeys = [];

    private bool $syncing = false;

    /** @var array<string, true> */
    private array $deletedRoots = [];

    /**
     * Reconciliation actions queued while a block sync is in flight.
     * Key: record path without the block prefix. Value: insert value, or
     * false for a delete. UDB rejects real-time INS/DEL while a staged
     * transaction is active, so writes are deferred until END/FDR.
     *
     * @var array<string, string|false>
     */
    private array $pendingActions = [];

    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private UdbRecordWriterInterface $recordWriter,
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordMigrationStateInterface $migrationState,
        private OperIrcopRepositoryInterface $ircopRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickPasswordProvidedEvent::class => 'onPasswordProvided',
            NickVhostChangedEvent::class => 'onVhostChanged',
            NickDropEvent::class => 'onNickDrop',
            UdbSyncRequestedEvent::class => 'onSyncRequested',
            UdbSyncCompleteEvent::class => 'onSyncComplete',
            UdbRecordReceivedEvent::class => 'onRecordReceived',
            OperRoleForcedVhostChangedEvent::class => 'onOperRoleForcedVhostChanged',
            OperIrcopChangedEvent::class => 'onOperIrcopChanged',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->queueDelete($event->nickname);
    }

    public function onPasswordProvided(NickPasswordProvidedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $this->migrationState->markAsMigrated($event->nickname);

        $hash = hash('sha256', $event->plaintextPassword);
        $this->queueInsert(sprintf('%s::pass', $event->nickname), sprintf('sha256:%s', $hash));

        if (null !== $event->nickId) {
            $nick = $this->nickRepository->findById($event->nickId);
            if (null !== $nick) {
                $effectiveVhost = $this->resolveEffectiveVhost($nick);
                if (null !== $effectiveVhost) {
                    $this->queueInsert(sprintf('%s::vhost', $event->nickname), $effectiveVhost);
                }
            }
        }
    }

    public function onVhostChanged(NickVhostChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);
        if (null !== $effectiveVhost) {
            $this->queueInsert(sprintf('%s::vhost', $event->nickname), $effectiveVhost);
        } else {
            $this->queueDelete(sprintf('%s::vhost', $event->nickname));
        }
    }

    public function onSyncRequested(UdbSyncRequestedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        if (self::BLOCK === $event->block) {
            $this->syncing = true;
            $this->receivedKeys = [];
            $this->deletedRoots = [];
            $this->pendingActions = [];

            // Request UDB's current N-block records so we can reconcile after the sync ends.
            $this->recordWriter->requestSync(self::BLOCK, $event->sourceSid);
        }
    }

    /**
     * Called when UDB finishes the block snapshot (END for staged transfers,
     * FDR for the legacy path). Missing records are queued and every pending
     * reconciliation action is flushed now that the staged session is closed.
     * Services' database is the source of truth: records present in UDB but
     * absent from services are deleted (queued by onRecordReceived) here.
     */
    public function onSyncComplete(UdbSyncCompleteEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        if (self::BLOCK !== $event->block || !$this->syncing) {
            return;
        }

        foreach ($this->canonicalRecords() as $key => $value) {
            if (!isset($this->receivedKeys[$this->normalizeKey(sprintf('%s::%s', self::BLOCK, $key))])) {
                $this->pendingActions[$key] = $value;
            }
        }

        $this->flushPendingActions();
        $this->resetSyncState();
    }

    public function onRecordReceived(UdbRecordReceivedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        // Example key: N::davidlig::pass
        $parts = explode('::', $event->key);
        if (count($parts) < 3 || self::BLOCK !== $parts[0]) {
            return;
        }

        // Reconciliation runs only inside the sync window; real-time frames
        // forwarded by UDB are authoritative and are not fought back.
        if (!$this->syncing) {
            return;
        }

        $nickname = $parts[1];
        $property = $parts[2];

        $this->receivedKeys[$this->normalizeKey($event->key)] = true;

        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            $root = sprintf('%s::%s', self::BLOCK, $nickname);
            $normalizedRoot = $this->normalizeKey($root);
            if (!isset($this->deletedRoots[$normalizedRoot])) {
                $this->deletedRoots[$normalizedRoot] = true;
                $this->queueDelete($nickname);
            }

            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);

        if ('pass' === $property) {
            $this->migrationState->markAsMigrated($nickname);
            $expectedHash = $account->getPasswordHash();
            if (null === $expectedHash) {
                $this->queueDelete(sprintf('%s::pass', $nickname));
            } elseif ($this->isUdbCompatiblePasswordHash($expectedHash) && $expectedHash !== $event->value) {
                $this->queueInsert(sprintf('%s::pass', $nickname), $expectedHash);
            }
        } elseif ('vhost' === $property) {
            if (null === $effectiveVhost) {
                $this->queueDelete(sprintf('%s::vhost', $nickname));
            } elseif ($effectiveVhost !== $event->value) {
                $this->queueInsert(sprintf('%s::vhost', $nickname), $effectiveVhost);
            }
        } elseif ('oper' === $property) {
            $ircop = $this->ircopRepository->findByNickId($account->getId());
            if (null === $ircop) {
                $this->queueDelete(sprintf('%s::oper', $nickname));
            }
        } elseif ('swhois' === $property) {
            $this->queueDelete(sprintf('%s::swhois', $nickname));
        } else {
            $this->queueDelete(substr($event->key, strlen(self::BLOCK) + 2));
        }
    }

    /** @return array<string, string> */
    private function canonicalRecords(): array
    {
        $records = [];
        foreach ($this->nickRepository->all() as $nick) {
            $passwordHash = $nick->getPasswordHash();
            if (null !== $passwordHash && $this->isUdbCompatiblePasswordHash($passwordHash)) {
                $records[sprintf('%s::pass', $nick->getNickname())] = $passwordHash;
            }

            $effectiveVhost = $this->resolveEffectiveVhost($nick);
            if (null !== $effectiveVhost) {
                $records[sprintf('%s::vhost', $nick->getNickname())] = $effectiveVhost;
            }
        }

        return $records;
    }

    private function isUdbCompatiblePasswordHash(string $passwordHash): bool
    {
        if (str_starts_with($passwordHash, 'argon2id:$argon2id$')) {
            return true;
        }

        if (str_starts_with($passwordHash, 'crypt:')) {
            return 6 < strlen($passwordHash);
        }

        return 1 === preg_match('/\Asha256:[0-9a-fA-F]{64}\z/', $passwordHash);
    }

    private function resetSyncState(): void
    {
        $this->syncing = false;
        $this->receivedKeys = [];
        $this->deletedRoots = [];
        $this->pendingActions = [];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower($key);
    }

    private function isUdbActive(): bool
    {
        $module = $this->connectionHolder->getProtocolModule();

        return null !== $module && 'unrealudb' === $module->getProtocolName();
    }

    public function onOperRoleForcedVhostChanged(OperRoleForcedVhostChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $ircops = $this->ircopRepository->findByRoleId($event->roleId);
        foreach ($ircops as $ircop) {
            $account = $this->nickRepository->findById($ircop->getNickId());
            if (null === $account) {
                continue;
            }

            $effectiveVhost = $this->resolveEffectiveVhost($account);
            if (null !== $effectiveVhost) {
                $this->queueInsert(sprintf('%s::vhost', $account->getNickname()), $effectiveVhost);
            } else {
                $this->queueDelete(sprintf('%s::vhost', $account->getNickname()));
            }
        }
    }

    public function onOperIrcopChanged(OperIrcopChangedEvent $event): void
    {
        if (!$this->isUdbActive()) {
            return;
        }

        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);
        if (null !== $effectiveVhost) {
            $this->queueInsert(sprintf('%s::vhost', $event->nickname), $effectiveVhost);
        } else {
            $this->queueDelete(sprintf('%s::vhost', $event->nickname));
        }
    }

    private function resolveEffectiveVhost(RegisteredNick $account): ?string
    {
        $ircop = $this->ircopRepository->findByNickId($account->getId());
        if (null !== $ircop) {
            $role = $ircop->getRole();
            $forcedPattern = $role->getForcedVhostPattern();
            if (null !== $forcedPattern && '' !== $forcedPattern && ForcedVhost::isValidPattern($forcedPattern)) {
                return ForcedVhost::fromPattern($forcedPattern)->generateVhost($account->getNickname());
            }
        }

        $personalVhost = $account->getVhost();
        if (null !== $personalVhost && '' !== trim($personalVhost)) {
            return trim($personalVhost);
        }

        return null;
    }

    private function queueInsert(string $path, string $value): void
    {
        if ($this->syncing) {
            $this->pendingActions[$path] = $value;

            return;
        }

        $this->recordWriter->insert(self::BLOCK, $path, $value);
    }

    private function queueDelete(string $path): void
    {
        if ($this->syncing) {
            $this->pendingActions[$path] = false;

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }

    private function flushPendingActions(): void
    {
        foreach ($this->pendingActions as $path => $value) {
            if (false === $value) {
                $this->recordWriter->delete(self::BLOCK, $path);
            }
        }

        foreach ($this->pendingActions as $path => $value) {
            if (false === $value || $this->hasPendingRootDelete($path)) {
                continue;
            }

            $this->recordWriter->insert(self::BLOCK, $path, $value);
        }
    }

    private function hasPendingRootDelete(string $path): bool
    {
        $pos = strpos($path, '::');
        $root = false === $pos ? $path : substr($path, 0, $pos);

        return isset($this->pendingActions[$root]) && false === $this->pendingActions[$root];
    }
}
