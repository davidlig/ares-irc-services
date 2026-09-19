<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;
use function str_ends_with;
use function strtolower;

/**
 * Repairs N-block pass records so the authoritative store always projects the
 * SQL password hash of every verified account.
 *
 * History: legacy code seeded and synced UDB passwords as unsalted `sha256:`
 * digests while PHP-only hashes (bcrypt `$2y$`) were silently skipped by the
 * exporter, leaving nicks without a usable UDB password and both stores
 * holding different hash formats. This runs after every completed network
 * sync (after UdbStoreInitializer) and:
 *
 * - inserts the normalized SQL hash when the pass record is missing,
 * - replaces legacy `sha256:` records and any raw UDB-incompatible value,
 * - for verified accounts, never overwrites explicit `crypt:` / `argon2id:`
 *   records because OperServ RAW mutations own those,
 * - deletes every pass record for accounts still awaiting verification,
 * - deletes the pass record when SQL holds no hash for the account.
 *
 * The repair is idempotent and safe to run on every sync.
 */
final readonly class UdbPasswordRepairSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'N';

    public function __construct(
        private NickProjectionQuery $nicks,
        private UdbRecordRepositoryInterface $records,
        private UdbRecordWriterInterface $recordWriter,
        private UdbRecordExporter $exporter,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Runs after UdbStoreInitializer (priority 0) so a fresh seed from the
        // exporter is observed as-is and never overwritten.
        return [
            NetworkSyncCompleteEvent::class => ['onNetworkSyncComplete', -10],
        ];
    }

    public function onNetworkSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $this->repair();
    }

    /** Repairs every account pass record. Returns the number of nicks fixed. */
    public function repair(): int
    {
        $storeHashes = $this->storePassHashes();

        $repaired = 0;
        foreach ($this->nicks->all() as $nick) {
            $encodedPath = UdbPathCodec::encodePath([$nick->nickname, 'pass']);
            if (null === $encodedPath) {
                continue;
            }

            $current = $storeHashes[strtolower($encodedPath)] ?? null;

            if ($nick->pendingVerification) {
                if (null !== $current && $this->recordWriter->delete(self::BLOCK, sprintf('%s::pass', $nick->nickname))) {
                    ++$repaired;
                }

                continue;
            }

            $sqlHash = $nick->passwordHash;

            if (null === $sqlHash || '' === $sqlHash) {
                if (null !== $current && $this->recordWriter->delete(self::BLOCK, sprintf('%s::pass', $nick->nickname))) {
                    ++$repaired;
                }

                continue;
            }

            $desired = $this->exporter->toUdbPasswordHash($sqlHash);
            if (null === $desired || $desired === $current || $this->isProtectedHash($current)) {
                continue;
            }

            if ($this->recordWriter->insert(self::BLOCK, sprintf('%s::pass', $nick->nickname), $desired)) {
                ++$repaired;
            }
        }

        if (0 < $repaired) {
            $this->logger->info('Repaired UDB pass records from SQL password hashes.', [
                'count' => $repaired,
            ]);
        }

        return $repaired;
    }

    /**
     * Store pass records keyed by lowercase identity path (UDB compares keys
     * case-insensitively), regardless of the nickname's stored casing.
     *
     * @return array<string, string>
     */
    private function storePassHashes(): array
    {
        $hashes = [];
        foreach ($this->records->recordsByBlock(self::BLOCK) as $path => $value) {
            $identity = strtolower($path);
            if (str_ends_with($identity, '::pass')) {
                $hashes[$identity] = $value;
            }
        }

        return $hashes;
    }

    /**
     * Explicit crypt:/argon2id: records belong to their owner (OperServ RAW
     * UDB mutations) and are never overwritten by the SQL projection; legacy
     * sha256: digests and raw PHP hashes are always re-projected.
     */
    private function isProtectedHash(?string $current): bool
    {
        if (null === $current) {
            return false;
        }

        return str_starts_with($current, 'crypt:') || str_starts_with($current, 'argon2id:');
    }
}
