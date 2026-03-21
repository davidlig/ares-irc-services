<?php

declare(strict_types=1);

namespace App\Tests\Application\ApplicationPort;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceNicknameRegistry::class)]
final class ServiceNicknameRegistryTest extends TestCase
{
    private function createProvider(string $key, string $nickname): ServiceNicknameProviderInterface
    {
        return new class($key, $nickname) implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
    }

    #[Test]
    public function getNicknameReturnsNicknameForRegisteredService(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
            $this->createProvider('chanserv', 'ChanServ'),
        ]);

        self::assertSame('NickServ', $registry->getNickname('nickserv'));
        self::assertSame('ChanServ', $registry->getNickname('chanserv'));
    }

    #[Test]
    public function getNicknameReturnsNullForUnknownService(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
        ]);

        self::assertNull($registry->getNickname('unknown'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredService(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
        ]);

        self::assertTrue($registry->has('nickserv'));
    }

    #[Test]
    public function hasReturnsFalseForUnknownService(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
        ]);

        self::assertFalse($registry->has('unknown'));
    }

    #[Test]
    public function getAllPlaceholdersReturnsBotPlaceholderAndAllServices(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
            $this->createProvider('chanserv', 'ChanServ'),
            $this->createProvider('memoserv', 'MemoServ'),
            $this->createProvider('operserv', 'OperServ'),
        ]);

        $placeholders = $registry->getAllPlaceholders('NickServ');

        self::assertSame('NickServ', $placeholders['%bot%']);
        self::assertSame('NickServ', $placeholders['%nickserv%']);
        self::assertSame('ChanServ', $placeholders['%chanserv%']);
        self::assertSame('MemoServ', $placeholders['%memoserv%']);
        self::assertSame('OperServ', $placeholders['%operserv%']);
    }

    #[Test]
    public function getAllPlaceholdersUsesProvidedBotNickname(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
        ]);

        $placeholders = $registry->getAllPlaceholders('CustomBot');

        self::assertSame('CustomBot', $placeholders['%bot%']);
        self::assertSame('NickServ', $placeholders['%nickserv%']);
    }

    #[Test]
    public function constructorHandlesEmptyProviders(): void
    {
        $registry = new ServiceNicknameRegistry([]);

        self::assertNull($registry->getNickname('nickserv'));
        self::assertFalse($registry->has('nickserv'));
        self::assertSame(['%bot%' => 'TestBot'], $registry->getAllPlaceholders('TestBot'));
    }

    #[Test]
    public function constructorOverwritesDuplicateKeys(): void
    {
        $registry = new ServiceNicknameRegistry([
            $this->createProvider('nickserv', 'NickServ'),
            $this->createProvider('nickserv', 'NickServ2'),
        ]);

        self::assertSame('NickServ2', $registry->getNickname('nickserv'));
    }
}
