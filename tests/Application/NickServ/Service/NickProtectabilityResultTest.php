<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(NickProtectabilityResult::class)]
final class NickProtectabilityResultTest extends TestCase
{
    #[Test]
    public function allowedCreatesResultWithAllowedStatus(): void
    {
        $result = NickProtectabilityResult::allowed('TestNick', null);

        self::assertTrue($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::Allowed, $result->status);
        self::assertSame('TestNick', $result->nickname);
        self::assertNull($result->account);
    }

    #[Test]
    public function allowedCreatesResultWithAccount(): void
    {
        $nick = $this->createNickWithId('TestNick', 42);

        $result = NickProtectabilityResult::allowed('TestNick', $nick);

        self::assertTrue($result->isAllowed());
        self::assertSame($nick, $result->account);
    }

    #[Test]
    public function rootCreatesResultWithRootStatus(): void
    {
        $result = NickProtectabilityResult::root('RootAdmin');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsRoot, $result->status);
        self::assertSame('RootAdmin', $result->nickname);
        self::assertNull($result->account);
    }

    #[Test]
    public function ircopCreatesResultWithIrcopStatus(): void
    {
        $result = NickProtectabilityResult::ircop('OperUser');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsIrcop, $result->status);
        self::assertSame('OperUser', $result->nickname);
        self::assertNull($result->account);
    }

    #[Test]
    public function serviceCreatesResultWithServiceStatus(): void
    {
        $result = NickProtectabilityResult::service('NickServ');

        self::assertFalse($result->isAllowed());
        self::assertSame(NickProtectabilityStatus::IsService, $result->status);
        self::assertSame('NickServ', $result->nickname);
        self::assertNull($result->account);
    }

    #[Test]
    public function isAllowedReturnsFalseForNonAllowedStatus(): void
    {
        $rootResult = NickProtectabilityResult::root('RootAdmin');
        $ircopResult = NickProtectabilityResult::ircop('OperUser');
        $serviceResult = NickProtectabilityResult::service('NickServ');

        self::assertFalse($rootResult->isAllowed());
        self::assertFalse($ircopResult->isAllowed());
        self::assertFalse($serviceResult->isAllowed());
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
