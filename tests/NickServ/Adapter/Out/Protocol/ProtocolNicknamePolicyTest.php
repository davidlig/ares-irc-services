<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Protocol;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\NickServ\Adapter\Out\Protocol\ProtocolNicknamePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(ProtocolNicknamePolicy::class)]
final class ProtocolNicknamePolicyTest extends TestCase
{
    #[Test]
    public function acceptsValidIrcNicknameSyntax(): void
    {
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $policy = new ProtocolNicknamePolicy($holder, 32, 30);

        self::assertTrue($policy->isValid('Alice'));
        self::assertTrue($policy->isValid('Bob_123'));
        self::assertTrue($policy->isValid('[Owner]|Work'));
        self::assertTrue($policy->isValid('`Nick^'));
        self::assertTrue($policy->isValid('{curly}'));
    }

    #[Test]
    public function rejectsInvalidIrcNicknameSyntax(): void
    {
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $policy = new ProtocolNicknamePolicy($holder, 32, 30);

        self::assertFalse($policy->isValid(''));
        self::assertFalse($policy->isValid('#not-a-nick'));
        self::assertFalse($policy->isValid('&channel'));
        self::assertFalse($policy->isValid('+plus'));
        self::assertFalse($policy->isValid('123digitfirst'));
        self::assertFalse($policy->isValid('-hyphenfirst'));
        self::assertFalse($policy->isValid('has space'));
        self::assertFalse($policy->isValid('nick@host'));
        self::assertFalse($policy->isValid('nick!ident'));
        self::assertFalse($policy->isValid('comma,nick'));
    }

    #[Test]
    public function limitsToMinOfDoctrineAndIrcdWhenIrcdIsSmaller(): void
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getMaxNickLength')->willReturn(30);

        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);

        $policy = new ProtocolNicknamePolicy($holder, doctrineColumnCapacity: 32);

        self::assertSame(30, $policy->getMaximumLength());

        // exactly at limit
        $atLimit = 'A' . str_repeat('a', 29); // 30 chars
        self::assertTrue($policy->isValid($atLimit));

        // one above limit
        $oneAbove = 'A' . str_repeat('a', 30); // 31 chars
        self::assertFalse($policy->isValid($oneAbove));
    }

    #[Test]
    public function limitsToMinOfDoctrineAndIrcdWhenDoctrineIsSmaller(): void
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getMaxNickLength')->willReturn(30);

        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);

        $policy = new ProtocolNicknamePolicy($holder, doctrineColumnCapacity: 20);

        self::assertSame(20, $policy->getMaximumLength());

        // exactly at limit
        $atLimit = 'A' . str_repeat('a', 19); // 20 chars
        self::assertTrue($policy->isValid($atLimit));

        // one above limit
        $oneAbove = 'A' . str_repeat('a', 20); // 21 chars
        self::assertFalse($policy->isValid($oneAbove));
    }

    #[Test]
    public function usesFallbackWhenNoProtocolModuleIsConnected(): void
    {
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn(null);

        $policy = new ProtocolNicknamePolicy($holder, doctrineColumnCapacity: 32, fallbackIrcdMaxNickLen: 28);

        self::assertSame(28, $policy->getMaximumLength());
        self::assertTrue($policy->isValid('A' . str_repeat('b', 27)));
        self::assertFalse($policy->isValid('A' . str_repeat('b', 28)));
    }
}
