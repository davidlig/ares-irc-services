<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command;

use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServCommandRegistry::class)]
final class MemoServCommandRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsNullForEmptyRegistry(): void
    {
        $registry = new MemoServCommandRegistry([]);

        self::assertNull($registry->find('SEND'));
    }

    #[Test]
    public function findReturnsHandlerByName(): void
    {
        $handler = $this->createHandler('SEND');
        $registry = new MemoServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('SEND'));
        self::assertSame($handler, $registry->find('send'));
    }

    #[Test]
    public function findReturnsHandlerByAlias(): void
    {
        $handler = $this->createHandler('LIST', ['LS']);
        $registry = new MemoServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('LS'));
    }

    #[Test]
    public function allReturnsUniqueCommands(): void
    {
        $h1 = $this->createHandler('SEND');
        $h2 = $this->createHandler('READ');
        $registry = new MemoServCommandRegistry([$h1, $h2]);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertContains($h1, $all);
        self::assertContains($h2, $all);
    }

    private function createHandler(string $name, array $aliases = []): MemoServCommandInterface
    {
        return new class($name, $aliases) implements MemoServCommandInterface {
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
            public function execute(MemoServContext $context): void {}
        };
    }
}
