<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

/**
 * UDB 4 block identifiers.
 *
 * The UDB database is distributed as six blocks (N, C, I, S, L, K) that are
 * always reconciled together as one correlated round keyed by round_id.
 * Services own all six blocks in its authoritative store.
 *
 * @see udb.h (UDB_BLOCK_NICKS .. UDB_BLOCK_LINES) in the UnrealIRCd UDB module.
 */
enum UdbBlock: string
{
    case Nicks = 'N';
    case Channels = 'C';
    case Ips = 'I';
    case Settings = 'S';
    case Links = 'L';
    case Lines = 'K';

    /** Block letter as used on the wire (single uppercase character). */
    public function letter(): string
    {
        return $this->value;
    }

    /** @return list<self> All six blocks in reconciliation order. */
    public static function all(): array
    {
        return [self::Nicks, self::Channels, self::Ips, self::Settings, self::Links, self::Lines];
    }

    public static function fromLetter(string $letter): ?self
    {
        return self::tryFrom($letter);
    }
}
