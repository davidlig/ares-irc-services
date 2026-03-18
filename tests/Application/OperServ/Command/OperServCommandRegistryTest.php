<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServCommandRegistry::class)]
final class OperServCommandRegistryTest extends TestCase
{
    #[Test]
    public function findReturnsNullForEmptyRegistry(): void
    {
        $registry = new OperServCommandRegistry([]);

        self::assertNull($registry->find('FOO'));
    }

    #[Test]
    public function allReturnsEmptyArrayForEmptyRegistry(): void
    {
        $registry = new OperServCommandRegistry([]);

        self::assertSame([], $registry->all());
    }

    #[Test]
    public function findReturnsHandlerByName(): void
    {
        $handler = $this->createHandler('ROLE');
        $registry = new OperServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('ROLE'));
    }

    #[Test]
    public function findReturnsHandlerByAlias(): void
    {
        $handler = $this->createHandler('ROLE', ['R']);
        $registry = new OperServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('R'));
    }

    #[Test]
    public function findReturnsNullWhenCommandNotFound(): void
    {
        $handler = $this->createHandler('ROLE');
        $registry = new OperServCommandRegistry([$handler]);

        self::assertNull($registry->find('NONEXISTENT'));
    }

    #[Test]
    public function findIsCaseInsensitiveForUppercase(): void
    {
        $handler = $this->createHandler('ROLE');
        $registry = new OperServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('ROLE'));
    }

    #[Test]
    public function findIsCaseInsensitiveForLowercase(): void
    {
        $handler = $this->createHandler('ROLE');
        $registry = new OperServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('role'));
    }

    #[Test]
    public function findIsCaseInsensitiveForMixedCase(): void
    {
        $handler = $this->createHandler('ROLE');
        $registry = new OperServCommandRegistry([$handler]);

        self::assertSame($handler, $registry->find('RoLe'));
    }

    #[Test]
    public function allReturnsUniqueCommandsWithoutDuplicatesFromAliases(): void
    {
        $handler = $this->createHandler('ROLE', ['R', 'ROLES']);
        $registry = new OperServCommandRegistry([$handler]);

        $all = $registry->all();
        self::assertCount(1, $all);
        self::assertContains($handler, $all);
    }

    #[Test]
    public function allReturnsAllCommandsWhenMultipleCommands(): void
    {
        $h1 = $this->createHandler('ROLE');
        $h2 = $this->createHandler('HELP');
        $h3 = $this->createHandler('IRCOP');
        $registry = new OperServCommandRegistry([$h1, $h2, $h3]);

        $all = $registry->all();
        self::assertCount(3, $all);
        self::assertContains($h1, $all);
        self::assertContains($h2, $all);
        self::assertContains($h3, $all);
    }

    #[Test]
    public function allReturnsUniqueCommandsWhenMultipleCommandsHaveAliases(): void
    {
        $h1 = $this->createHandler('ROLE', ['R']);
        $h2 = $this->createHandler('HELP', ['H', '?']);
        $registry = new OperServCommandRegistry([$h1, $h2]);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertContains($h1, $all);
        self::assertContains($h2, $all);
    }

    private function createHandler(string $name, array $aliases = []): OperServCommandInterface
    {
        return new class($name, $aliases) implements OperServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly array $aliases,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return $this->aliases;
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(OperServContext $context): void
            {
            }
        };
    }
}
