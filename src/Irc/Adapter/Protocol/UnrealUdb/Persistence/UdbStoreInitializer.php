<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbStoreInitializationListener;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function in_array;

/**
 * One-time seeding of the authoritative UDB store.
 *
 * On the first completed network sync each uninitialized block is seeded:
 * N/C/K are merged from the services SQL export (nick passwords, vhosts,
 * opers, channel founder/topic/modes/options, active GLINEs) while
 * I/S/L start empty. A UdbBlockState row per block marks completion so the
 * seed never runs again: afterwards the store is the sole authority and RAW
 * or SQL mutations win over any stale snapshot.
 */
final class UdbStoreInitializer implements EventSubscriberInterface
{
    public function __construct(
        private readonly UdbRecordRepositoryInterface $records,
        private readonly UdbBlockStateRepositoryInterface $states,
        private readonly UdbRecordExporter $exporter,
        private readonly UdbStoreInitializationListener $coordinator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => 'onNetworkSyncComplete',
        ];
    }

    public function onNetworkSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $this->ensureInitialized();
    }

    /**
     * Seeds every uninitialized block. Returns true when at least one block
     * was seeded (the store may have become ready just now).
     */
    public function ensureInitialized(): bool
    {
        $missing = array_diff(
            array_map(static fn (UdbBlock $block): string => $block->letter(), UdbBlock::all()),
            array_keys($this->states->all()),
        );

        if ([] === $missing) {
            $this->coordinator->onStoreInitialized();

            return false;
        }

        foreach (UdbBlock::all() as $block) {
            if (!in_array($block->letter(), $missing, true)) {
                continue;
            }

            $this->seedBlock($block);
        }

        $this->logger->info('UDB store initialized; services are the sole UDB authority.', [
            'blocks' => implode(',', $missing),
        ]);
        $this->coordinator->onStoreInitialized();

        return true;
    }

    private function seedBlock(UdbBlock $block): void
    {
        $seed = $this->exporter->encodedBlockRecords($block);

        $this->records->seedBlock($block->letter(), $seed);
        $records = $this->records->recordsByBlock($block->letter());
        $this->states->upsert($block->letter(), UdbChecksum::fromMap($block, $records), count($records));
    }
}
