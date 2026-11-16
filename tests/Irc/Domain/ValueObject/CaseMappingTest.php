<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\ValueObject;

use App\Irc\Domain\ValueObject\CaseMapping;
use App\Shared\Domain\IrcIdentityFold;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaseMapping::class)]
final class CaseMappingTest extends TestCase
{
    #[Test]
    public function rfc1459MatchesTheServiceIdentityFold(): void
    {
        $value = '[Foo]\\Bar^';

        self::assertSame(IrcIdentityFold::fold($value), CaseMapping::Rfc1459->fold($value));
        self::assertSame('{foo}|bar~', CaseMapping::Rfc1459->fold($value));
    }

    #[Test]
    public function otherMappingsKeepTheirOwnSemantics(): void
    {
        self::assertSame('[foo]\\bar^', CaseMapping::Ascii->fold('[Foo]\\Bar^'));
        self::assertSame('{foo}|bar^', CaseMapping::StrictRfc1459->fold('[Foo]\\Bar^'));
    }
}
