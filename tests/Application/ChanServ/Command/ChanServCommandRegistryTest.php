<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServCommandRegistry::class)]
final class ChanServCommandRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsNullForEmptyRegistry(): void
    {
        $registry = new ChanServCommandRegistry([]);

        self::assertNull($registry->find('FOO'));
    }

    #[Test]
    public function findReturnsHandlerByName(): void
    {
        $handler = $this->createHandler('REGISTER');
        $registry = new ChanServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('REGISTER'));
        self::assertSame($handler, $registry->find('register'));
    }

    #[Test]
    public function findReturnsHandlerByAlias(): void
    {
        $handler = $this->createHandler('REGISTER', ['REG']);
        $registry = new ChanServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('REG'));
    }

    #[Test]
    public function allReturnsUniqueCommands(): void
    {
        $h1 = $this->createHandler('A');
        $h2 = $this->createHandler('B');
        $registry = new ChanServCommandRegistry([$h1, $h2]);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertContains($h1, $all);
        self::assertContains($h2, $all);
    }

    private function createHandler(string $name, array $aliases = []): ChanServCommandInterface
    {
        return new class($name, $aliases) implements ChanServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly array $aliases,
            ) {}

            public function getName(): string { return $this->name; }
            public function getAliases(): array { return $this->aliases; }
            public function getMinArgs(): int { return 0; }
            public function getSyntaxKey(): string { return ''; }
            public function getHelpKey(): string { return ''; }
            public function getOrder(): int { return 0; }
            public function getShortDescKey(): string { return ''; }
            public function getSubCommandHelp(): array { return []; }
            public function isOperOnly(): bool { return false; }
            public function getRequiredPermission(): ?string { return null; }
            public function execute(ChanServContext $context): void {}
        };
    }
}
