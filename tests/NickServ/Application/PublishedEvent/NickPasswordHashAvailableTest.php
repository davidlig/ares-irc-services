<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\PublishedEvent;

use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickPasswordHashAvailable::class)]
final class NickPasswordHashAvailableTest extends TestCase
{
    #[Test]
    public function carriesOnlyTheSafePasswordProjection(): void
    {
        $event = new NickPasswordHashAvailable(1, 'nick', '$2y$12$hash');

        self::assertSame(1, $event->nickId);
        self::assertSame('nick', $event->nickname);
        self::assertSame('$2y$12$hash', $event->passwordHash);
        self::assertSame(['nickId', 'nickname', 'passwordHash'], array_keys(get_object_vars($event)));
    }

    #[Test]
    public function identifiersAndPasswordHashAreNullable(): void
    {
        $event = new NickPasswordHashAvailable(null, 'nick', null);

        self::assertNull($event->nickId);
        self::assertNull($event->passwordHash);
    }
}
