<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;

/**
 * Records that an AKICK entry caught a member.
 *
 * The enforcement matches a rule value object, so the entries the rules were
 * built from are kept next to them: the use is stamped on the entry that
 * produced the matching rule and not on the rule itself.
 */
final readonly class AkickUseRecorder
{
    public function __construct(private ChannelAkickRepositoryInterface $akicks) {}

    /**
     * @param list<ChannelAkick> $entries The entries the rules were built from, in the same order
     * @param list<AkickRule>    $rules   The rules built from those entries, in the same order
     */
    public function record(array $entries, array $rules, AkickRule $matching, DateTimeImmutable $usedAt): void
    {
        foreach ($rules as $index => $rule) {
            if ($rule !== $matching) {
                continue;
            }

            $entry = $entries[$index] ?? null;
            if (null === $entry) {
                return;
            }

            $entry->recordUse($usedAt);
            $this->akicks->save($entry);

            return;
        }
    }
}
