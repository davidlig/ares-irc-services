<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Support;

use App\ChanServ\Adapter\Out\Network\IrcNetworkIdentityFold;
use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Adapter\Network\NetworkIdentityEquivalenceTracker;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\NickServ\Adapter\Out\User\IrcNetworkNickEquivalence;
use App\OperServ\Adapter\Out\Security\IrcNetworkNickEquivalence as OperServNickEquivalence;
use Closure;

/** Services' view of a link that negotiated the given casemapping (RFC 1459 unless stated). */
final class NegotiatedCaseMapping
{
    public static function network(CaseMapping $mapping = CaseMapping::Rfc1459): NetworkIdentityEquivalenceTracker
    {
        $tracker = new NetworkIdentityEquivalenceTracker();
        $tracker->onCaseMappingChanged(new CaseMappingChangedEvent($mapping));

        return $tracker;
    }

    public static function nickServ(CaseMapping $mapping = CaseMapping::Rfc1459): IrcNetworkNickEquivalence
    {
        return new IrcNetworkNickEquivalence(self::network($mapping));
    }

    public static function chanServ(CaseMapping $mapping = CaseMapping::Rfc1459): IrcNetworkIdentityFold
    {
        return new IrcNetworkIdentityFold(self::network($mapping));
    }

    public static function operServ(CaseMapping $mapping = CaseMapping::Rfc1459): OperServNickEquivalence
    {
        return new OperServNickEquivalence(self::network($mapping));
    }

    /** @return Closure(string): string */
    public static function fold(CaseMapping $mapping = CaseMapping::Rfc1459): Closure
    {
        return self::network($mapping)->fold(...);
    }
}
