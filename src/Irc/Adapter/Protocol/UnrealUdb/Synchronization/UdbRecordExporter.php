<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\UdbChannelModesFormatter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
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
        private RegisteredNickRepositoryInterface $nickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private OperIrcopRepositoryInterface $ircopRepository,
        private GlineRepositoryInterface $glineRepository,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private UdbChannelModesFormatter $modesFormatter = new UdbChannelModesFormatter(),
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
    public function effectiveVhost(RegisteredNick $nick): ?string
    {
        $ircop = $this->ircopRepository->findByNickId($nick->getId());
        if (null !== $ircop) {
            $forcedPattern = $ircop->getRole()->getForcedVhostPattern();
            if (null !== $forcedPattern && '' !== $forcedPattern && ForcedVhost::isValidPattern($forcedPattern)) {
                return ForcedVhost::fromPattern($forcedPattern)->generateVhost($nick->getNickname());
            }
        }

        $personalVhost = $nick->getVhost();
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
        foreach ($this->nickRepository->all() as $nick) {
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
        foreach ($this->channelRepository->listAll() as $channel) {
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
        foreach ($this->glineRepository->findActive() as $gline) {
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
     *
     * @return array<string, string>
     */
    public function nickRecords(RegisteredNick $nick): array
    {
        $records = [];

        $udbHash = $this->toUdbPasswordHash($nick->getPasswordHash());
        if (null !== $udbHash) {
            $records[sprintf('%s::pass', $nick->getNickname())] = $udbHash;
        }

        $vhost = $this->effectiveVhost($nick);
        if (null !== $vhost) {
            $records[sprintf('%s::vhost', $nick->getNickname())] = $vhost;
        }

        $ircop = $this->ircopRepository->findByNickId($nick->getId());
        $operclass = $ircop?->getRole()->getOperclass();
        if (null !== $operclass && '' !== $operclass) {
            $records[sprintf('%s::oper', $nick->getNickname())] = $operclass;
        }

        return $records;
    }

    /**
     * Full C-block profile of one channel (founder/topic/modes/options/access).
     * Forbidden channels export only their forbid record.
     *
     * @return array<string, string>
     */
    public function channelRecords(RegisteredChannel $channel): array
    {
        if ($channel->isForbidden()) {
            $reason = $channel->getForbiddenReason();
            if (null === $reason || '' === $reason) {
                return [];
            }

            return [sprintf('%s::forbid', $channel->getName()) => $reason];
        }

        $records = [];

        if ($channel->isSuspended()) {
            $records[sprintf('%s::suspended', $channel->getName())] = '1';
        }

        $founder = $this->nickRepository->findById($channel->getFounderNickId());
        if (null !== $founder) {
            $records[sprintf('%s::founder', $channel->getName())] = $founder->getNickname();
        }

        // Empty topics are not valid UDB records (string validators reject
        // empty values), so they are never exported.
        if (null !== $channel->getTopic() && '' !== $channel->getTopic()) {
            $records[sprintf('%s::topic', $channel->getName())] = $channel->getTopic();
        }

        if ($channel->isMlockActive()) {
            $formatted = $this->modesFormatter->format($channel->getMlock(), $channel->getMlockParams(), $this->modeSupportProvider->getSupport());
            if (null !== $formatted) {
                $records[sprintf('%s::modes', $channel->getName())] = $formatted;
            }
        }

        $options = $this->channelOptions($channel);
        if (0 !== $options) {
            $records[sprintf('%s::options', $channel->getName())] = '*' . $options;
        }

        foreach ($this->accessRepository->listByChannel($channel->getId()) as $access) {
            $records += $this->accessRecord($channel, $access);
        }

        return $records;
    }

    /**
     * Channel option bitmask: LOCK_MODES (2) = MLOCK active, LOCK_TOPIC (4) =
     * TOPICLOCK, PERSISTENT (8) = active registered channel (+P).
     */
    public function channelOptions(RegisteredChannel $channel): int
    {
        $options = 0;
        if ($channel->isMlockActive()) {
            $options |= 2;
        }
        if ($channel->isTopicLock()) {
            $options |= 4;
        }
        if (!$channel->isForbidden() && !$channel->isSuspended() && !$channel->isPendingDeletion()) {
            $options |= 8;
        }

        return $options;
    }

    /** @return array<string, string> Single-element map, or [] when the entry is not exportable. */
    public function accessRecord(RegisteredChannel $channel, ChannelAccess $access): array
    {
        $targetNick = $this->nickRepository->findById($access->getNickId());
        if (null === $targetNick) {
            return [];
        }

        $path = sprintf('%s::access::%s', $channel->getName(), $targetNick->getNickname());

        return [$path => (string) $access->getLevel()];
    }

    /**
     * K-block GLINE records: root reason, ::reason child and ::duration child.
     * Duration is the original ban length (expiresAt - createdAt) so snapshots
     * stay deterministic; the IRCd re-arms the ban with it on apply.
     *
     * @return array<string, string>
     */
    public function glineRecords(Gline $gline): array
    {
        $reason = $gline->getReason();
        if (null === $reason || '' === $reason) {
            return [];
        }

        $records = [
            sprintf('G::%s', $gline->getMask()) => $reason,
            sprintf('G::%s::reason', $gline->getMask()) => $reason,
        ];

        $expiresAt = $gline->getExpiresAt();
        if (null !== $expiresAt) {
            $duration = $expiresAt->getTimestamp() - $gline->getCreatedAt()->getTimestamp();
            if ($duration > 0) {
                $records[sprintf('G::%s::duration', $gline->getMask())] = '*' . $duration;
            }
        }

        return $records;
    }
}
