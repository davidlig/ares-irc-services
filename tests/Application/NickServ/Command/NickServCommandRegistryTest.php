<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServCommandRegistry::class)]
final class NickServCommandRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsNullForEmptyRegistry(): void
    {
        $registry = new NickServCommandRegistry([]);

        self::assertNull($registry->find('REGISTER'));
    }

    #[Test]
    public function findReturnsHandlerByName(): void
    {
        $handler = $this->createHandler('REGISTER');
        $registry = new NickServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('REGISTER'));
        self::assertSame($handler, $registry->find('register'));
    }

    #[Test]
    public function findReturnsHandlerByAlias(): void
    {
        $handler = $this->createHandler('IDENTIFY', ['ID']);
        $registry = new NickServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('ID'));
    }

    #[Test]
    public function allReturnsUniqueCommands(): void
    {
        $h1 = $this->createHandler('REGISTER');
        $h2 = $this->createHandler('INFO');
        $registry = new NickServCommandRegistry([$h1, $h2]);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertContains($h1, $all);
        self::assertContains($h2, $all);
    }

    private function createHandler(string $name, array $aliases = []): NickServCommandInterface
    {
        return new class($name, $aliases) implements NickServCommandInterface {
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
            public function execute(NickServContext $context): void {}
        };
    }
}
