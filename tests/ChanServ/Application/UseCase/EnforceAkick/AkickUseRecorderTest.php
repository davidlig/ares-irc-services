<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\AkickUseRecorder;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AkickUseRecorder::class)]
final class AkickUseRecorderTest extends TestCase
{
    #[Test]
    public function itStampsTheUseOnTheEntryThatProducedTheMatchingRule(): void
    {
        $first = ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@first.host');
        $second = ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@second.host');
        $rules = [$this->rule('*!*@first.host'), $this->rule('*!*@second.host')];

        $saved = [];
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('save')->willReturnCallback(static function (ChannelAkick $entry) use (&$saved): void {
            $saved[] = $entry;
        });

        $usedAt = new DateTimeImmutable('2026-10-20 12:00:00 UTC');
        new AkickUseRecorder($akicks)->record([$first, $second], $rules, $rules[1], $usedAt);

        self::assertSame(0, $first->getUses());
        self::assertNull($first->getLastUsedAt());
        self::assertSame(1, $second->getUses());
        self::assertSame($usedAt, $second->getLastUsedAt());
        self::assertSame([$second], $saved);
    }

    #[Test]
    public function aMatchingRuleWithoutAnEntryIsIgnored(): void
    {
        $entry = ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@first.host');
        $rules = [$this->rule('*!*@first.host')];

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('save');

        new AkickUseRecorder($akicks)->record([], $rules, $rules[0], new DateTimeImmutable());

        self::assertSame(0, $entry->getUses());
    }

    private function rule(string $mask): AkickRule
    {
        return new AkickRule(new AkickMask($mask), 'reason');
    }
}
