<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingChannelRankSynchronizations::class)]
final class PendingChannelRankSynchronizationsTest extends TestCase
{
    #[Test]
    public function itCoalescesCaseInsensitiveNamesAndReleasesOnlyTheMessageStartSnapshot(): void
    {
        $pending = new PendingChannelRankSynchronizations();
        $pending->schedule('#First');
        $pending->schedule('#FIRST');
        $pending->beginMessage();
        $pending->schedule('#Second');

        self::assertSame(['#first'], $pending->releaseMessageStartSnapshot());

        $pending->beginMessage();

        self::assertSame(['#second'], $pending->releaseMessageStartSnapshot());
        self::assertSame([], $pending->releaseMessageStartSnapshot());
    }
}
