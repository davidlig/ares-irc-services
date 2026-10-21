<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Register;

use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RegisteredNick::class)]
#[CoversClass(RegisterNick::class)]
final class TokenRegistrationTest extends TestCase
{
    public function testTokenPendingCanOmitEmailAndDoesNotHavePersistentCredential(): void
    {
        $nick = RegisteredNick::createPending('Token', 'hash', null, 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        self::assertFalse(new ReflectionClass($nick)->hasProperty('registrationMode'));
        self::assertFalse(method_exists($nick, 'getRegistrationMode'));
        self::assertNull($nick->getEmail());
        self::assertNull($nick->getRescueTokenHash());
        self::assertTrue($nick->isPending());
        $input = new RegisterNick('Token', 'password', null, 'en', 'client');
        self::assertNull($input->email);
    }
}
