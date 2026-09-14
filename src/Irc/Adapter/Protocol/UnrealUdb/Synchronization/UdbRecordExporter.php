<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\ChanServ\Application\Port\In\ChannelAccessProjection;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\SystemUdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\UdbChannelModesFormatter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use RuntimeException;

use function array_filter;
use function explode;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Maps services SQL state to UDB 4 records.
 *
 * Shared by the incremental export subscribers and the snapshot provider so
 * both always produce byte-identical records for the same SQL state.
 * Paths are returned RAW (unencoded, without block prefix); callers encode
 * them with UdbPathCodec before touching the wire.
 */
final readonly class UdbRecordExporter
{
    public function __construct(
        private NickProjectionQuery $nicks,
        private ChannelProjectionQuery $channels,
        private OperatorNetworkProjectionQuery $operators,
        private GlineProjectionQuery $glines,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private UdbChannelModesFormatter $modesFormatter = new UdbChannelModesFormatter(),
        private UdbClock $clock = new SystemUdbClock(),
    ) {}

    public function getChannelLookup(): ChannelLookupPort
    {
        return $this->channelLookup;
    }

    /**
     * Projects the SQL bcrypt hash into its UDB form.
     *
     * SQL stores bcrypt ($2y$, produced by PhpPasswordHasher) and UDB verifies
     * it through crypt() (AUTHTYPE_UNIXCRYPT), so the stored hash is only
     * re-labeled with the `crypt:` scheme prefix — never re-computed. Both
     * stores therefore verify the same password. Returns null when there is
     * no hash or it is not a well-formed bcrypt hash.
     */
    public function toUdbPasswordHash(?string $passwordHash): ?string
    {
        if (null === $passwordHash || 1 !== preg_match('/\A\$2y\$[0-9]{2}\$[A-Za-z0-9.\/]{53}\z/', $passwordHash)) {
            return null;
        }

        return 'crypt:' . $passwordHash;
    }

    /** vhost as seen by the network: role-forced pattern wins over personal vhost. */
    public function effectiveVhost(NickProjection $nick): ?string
    {
        $operator = $this->operators->findForNick($nick->id, $nick->nickname);
        if (null !== $operator?->forcedVhost) {
            return $operator->forcedVhost;
        }

        $personalVhost = $nick->vhost;
        if (null !== $personalVhost && '' !== trim($personalVhost)) {
            return trim($personalVhost);
        }

        return null;
    }

    /**
     * Aggregated N-block records of every registered nick.
     *
     * @return array<string, string>
     */
    public function allNickRecords(): array
    {
        $records = [];
        foreach ($this->nicks->all() as $nick) {
            $records += $this->nickRecords($nick);
        }

        return $records;
    }

    /**
     * Aggregated C-block records of every registered channel.
     *
     * @return array<string, string>
     */
    public function allChannelRecords(): array
    {
        $records = [];
        foreach ($this->channels->all() as $channel) {
            $records += $this->channelRecords($channel);
        }

        return $records;
    }

    /**
     * Aggregated K-block records of every active GLINE.
     *
     * @return array<string, string>
     */
    public function allGlineRecords(): array
    {
        $records = [];
        foreach ($this->glines->active() as $gline) {
            $records += $this->glineRecords($gline);
        }

        return $records;
    }

    /**
     * SQL-owned block rebuilt from the current SQL export, with encoded paths
     * ready for the authoritative store (and for replaceBlock()). Empty values
     * are filtered out: UDB rejects empty-value records and the store
     * checksum must stay in sync with what reconciliation serves.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException when the SQL export contains an invalid record
     */
    public function encodedBlockRecords(UdbBlock $block): array
    {
        $raw = match ($block) {
            UdbBlock::Nicks => $this->allNickRecords(),
            UdbBlock::Channels => $this->allChannelRecords(),
            UdbBlock::Lines => $this->allGlineRecords(),
            default => [],
        };

        $records = [];
        foreach ($raw as $rawPath => $value) {
            $components = explode('::', $rawPath);
            $path = UdbPathCodec::encodePath($components);
            if (null === $path || !UdbSchema::validate($block, $components, $value)) {
                throw new RuntimeException(sprintf('Current SQL export contains an invalid %s-block record.', $block->letter()));
            }

            $records[$path] = $value;
        }

        return array_filter($records, static fn (string $value): bool => '' !== $value);
    }

    /**
     * Full N-block profile of one nick (pass/vhost/oper).
     * Forbidden nicks export only their forbid record.
     *
     * @return array<string, string>
     */
    public function nickRecords(NickProjection $nick): array
    {
        if ($nick->forbidden) {
            $reason = $nick->forbiddenReason;
            if (null === $reason || '' === $reason) {
                return [];
            }

            return [sprintf('%s::forbid', $nick->nickname) => $reason];
        }

        $records = [];

        $udbHash = $this->toUdbPasswordHash($nick->passwordHash);
        if (null !== $udbHash) {
            $records[sprintf('%s::pass', $nick->nickname)] = $udbHash;
        }

        $vhost = $this->effectiveVhost($nick);
        if (null !== $vhost) {
            $records[sprintf('%s::vhost', $nick->nickname)] = $vhost;
        }

        $operclass = $this->operators->findForNick($nick->id, $nick->nickname)?->operclass;
        if (null !== $operclass && '' !== $operclass) {
            $records[sprintf('%s::oper', $nick->nickname)] = $operclass;
        }

        return $records;
    }

    /**
     * Full C-block profile of one channel (founder/topic/modes/options/access).
     * Forbidden channels export only their forbid record.
     *
     * @return array<string, string>
     */
    public function channelRecords(ChannelProjection $channel): array
    {
        if ($channel->forbidden) {
            $reason = $channel->forbiddenReason;
            if (null === $reason || '' === $reason) {
                return [];
            }

            return [sprintf('%s::forbid', $channel->name) => $reason];
        }

        $records = [];

        if ($channel->suspended) {
            $records[sprintf('%s::suspend', $channel->name)] = '1';
        }

        $founder = $this->nicks->findById($channel->founderNickId);
        if (null !== $founder) {
            $records[sprintf('%s::founder', $channel->name)] = $founder->nickname;
        }

        // Empty topics are not valid UDB records (string validators reject
        // empty values), so they are never exported.
        if (null !== $channel->topic && '' !== $channel->topic) {
            $records[sprintf('%s::topic', $channel->name)] = $channel->topic;
        }

        if ($channel->mlockActive) {
            $formatted = $this->modesFormatter->format($channel->mlock, $channel->mlockParams, $this->modeSupportProvider->getSupport());
            if (null !== $formatted) {
                $records[sprintf('%s::modes', $channel->name)] = $formatted;
            }
        }

        $options = $this->channelOptions($channel);
        if (0 !== $options) {
            $records[sprintf('%s::options', $channel->name)] = '*' . $options;
        }

        foreach ($channel->access as $access) {
            $records += $this->accessRecord($channel, $access);
        }

        return $records;
    }

    /** Channel option bitmask: LOCK_MODES (2) = MLOCK active, LOCK_TOPIC (4) = TOPICLOCK. */
    public function channelOptions(ChannelProjection $channel): int
    {
        $options = 0;
        if ($channel->mlockActive) {
            $options |= 2;
        }
        if ($channel->topicLock) {
            $options |= 4;
        }

        return $options;
    }

    /** @return array<string, string> Single-element map, or [] when the entry is not exportable. */
    public function accessRecord(ChannelProjection $channel, ChannelAccessProjection $access): array
    {
        $targetNick = $this->nicks->findById($access->nickId);
        if (null === $targetNick) {
            return [];
        }

        $path = sprintf('%s::access::%s', $channel->name, $targetNick->nickname);

        return [$path => (string) $access->level];
    }

    /**
     * K-block GLINE profile leaves. The pattern node is only a container;
     * temporary lines carry their original absolute expiry so reconciliation
     * cannot renew them after a restart or reconnect.
     *
     * @return array<string, string>
     */
    public function glineRecords(GlineProjection $gline): array
    {
        $reason = $gline->reason;
        if (null === $reason || '' === $reason) {
            return [];
        }

        $expiresAt = $gline->expiresAt;
        if (null !== $expiresAt && $expiresAt->getTimestamp() <= $this->clock->now()) {
            return [];
        }

        $records = [];
        if (null !== $expiresAt) {
            $records[sprintf('G::%s::expires', $gline->mask)] = '*' . $expiresAt->getTimestamp();
        }
        $records[sprintf('G::%s::reason', $gline->mask)] = $reason;

        return $records;
    }
}
