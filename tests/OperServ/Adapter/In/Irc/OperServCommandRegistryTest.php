<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServCommandRegistry::class)]
final class OperServCommandRegistryTest extends TestCase
{
    #[Test]
    public function indexesNamesAndAliasesCaseInsensitivelyWithoutDuplicatingCommands(): void
    {
        $first = new RegistryCommand('FIRST', ['F', 'ONE']);
        $second = new RegistryCommand('SECOND');
        $registry = new OperServCommandRegistry([$first, $second]);

        self::assertSame($first, $registry->find('first'));
        self::assertSame($first, $registry->find('f'));
        self::assertSame($first, $registry->find('ONE'));
        self::assertSame($second, $registry->find('second'));
        self::assertNull($registry->find('missing'));
        self::assertSame([$first, $second], $registry->all());
    }
}

final readonly class RegistryCommand implements OperServCommandInterface
{
    /** @param list<string> $aliases */
    public function __construct(private string $name, private array $aliases = []) {}

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

    public function execute(OperServContext $context): void {}
}
