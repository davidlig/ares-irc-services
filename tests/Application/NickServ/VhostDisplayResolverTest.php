<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\VhostDisplayResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VhostDisplayResolver::class)]
final class VhostDisplayResolverTest extends TestCase
{
    #[Test]
    public function returnsEmptyWhenStoredIsNull(): void
    {
        $resolver = new VhostDisplayResolver();

        self::assertSame('', $resolver->getDisplayVhost(null));
    }

    #[Test]
    public function returnsEmptyWhenStoredIsEmpty(): void
    {
        $resolver = new VhostDisplayResolver();

        self::assertSame('', $resolver->getDisplayVhost(''));
    }

    #[Test]
    public function returnsStoredWhenNoSuffix(): void
    {
        $resolver = new VhostDisplayResolver('');

        self::assertSame('my-vhost', $resolver->getDisplayVhost('my-vhost'));
    }

    #[Test]
    public function appendsSuffixWhenConfigured(): void
    {
        $resolver = new VhostDisplayResolver('virtual');

        self::assertSame('my-vhost.virtual', $resolver->getDisplayVhost('my-vhost'));
    }

    #[Test]
    public function suffixIsTrimmed(): void
    {
        $resolver = new VhostDisplayResolver('  virtual  ');

        self::assertSame('my-vhost.virtual', $resolver->getDisplayVhost('my-vhost'));
    }
}
