<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Network;

use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\ChannelName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaseMapping::class)]
#[CoversClass(Channel::class)]
final class ChannelCaseMappingTest extends TestCase
{
    #[Test]
    public function listMapsPreserveFirstSpellingAndInsertionOrder(): void
    {
        foreach (CaseMapping::cases() as $mapping) {
            $channel = new Channel(new ChannelName('#test'), '', new DateTimeImmutable('@0'), $mapping);
            foreach ([['addBan', 'removeBan', 'getBans'], ['addExempt', 'removeExempt', 'getExempts'], ['addInviteException', 'removeInviteException', 'getInviteExceptions']] as [$add, $remove, $get]) {
                $channel->$add('nick[a]!*@*');
                $channel->$add('second!*@*');
                $channel->$add('NICK{A}!*@*');
                $channel->$add('nick[a]!*@*');
                self::assertSame(CaseMapping::Ascii === $mapping ? ['nick[a]!*@*', 'second!*@*', 'NICK{A}!*@*'] : ['nick[a]!*@*', 'second!*@*'], $channel->$get());
                $channel->$remove('NICK{A}!*@*');
                $channel->$remove('missing!*@*');
                self::assertSame(CaseMapping::Ascii === $mapping ? ['nick[a]!*@*', 'second!*@*'] : ['second!*@*'], $channel->$get());
            }
        }
    }

    #[Test]
    public function foldingIsAsciiOnlyAndDistinguishesStrictMapping(): void
    {
        self::assertSame('ä{}|~', CaseMapping::Rfc1459->fold('ä[]\\^'));
        self::assertSame('{}|^', CaseMapping::StrictRfc1459->fold('[]\\^'));
        self::assertSame('[]\\^abc', CaseMapping::Ascii->fold('[]\\^ABC'));
    }
}
