<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Security;

use App\Irc\Domain\ValueObject\CaseMapping;
use App\OperServ\Adapter\Out\Security\IrcNetworkNickEquivalence;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcNetworkNickEquivalence::class)]
final class IrcNetworkNickEquivalenceTest extends TestCase
{
    #[Test]
    public function nicknamesAreEquivalentUnderTheNegotiatedCasemapping(): void
    {
        $ascii = new IrcNetworkNickEquivalence(NegotiatedCaseMapping::network(CaseMapping::Ascii));
        $rfc1459 = new IrcNetworkNickEquivalence(NegotiatedCaseMapping::network(CaseMapping::Rfc1459));

        self::assertTrue($ascii->equivalent('Foo[', 'FOO['));
        self::assertFalse($ascii->equivalent('Foo[', 'foo{'));
        self::assertTrue($rfc1459->equivalent('Foo[', 'foo{'));
    }
}
