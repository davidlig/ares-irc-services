<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CRegServCommandRegistry::class)]
final class CRegServCommandRegistryTest extends TestCase
{
    public function testFindsCommandByUppercaseNameAliasAndLowercaseInput(): void
    {
        $command = $this->createCommand('REGISTRA', ['REQUEST', 'REG']);
        $registry = new CRegServCommandRegistry([$command]);

        self::assertSame($command, $registry->find('REGISTRA'));
        self::assertSame($command, $registry->find('registra'));
        self::assertSame($command, $registry->find('REQUEST'));
        self::assertSame($command, $registry->find('reg'));
        self::assertNull($registry->find('NOPE'));
    }

    public function testAllReturnsUniqueCommandInstances(): void
    {
        $first = $this->createCommand('REGISTRA', ['REG']);
        $second = $this->createCommand('ESTADO', []);
        $registry = new CRegServCommandRegistry([$first, $second]);

        // REG alias maps to the same instance; all() must not duplicate it.
        self::assertCount(2, $registry->all());
    }

    /**
     * @param string[] $aliases
     */
    private function createCommand(string $name, array $aliases): CRegServCommandInterface
    {
        return new class($name, $aliases) implements CRegServCommandInterface {
            /** @param string[] $aliases */
            public function __construct(
                private readonly string $name,
                private readonly array $aliases,
            ) {}

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
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
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

            public function execute(CRegServContext $context): ?CommandOutcome
            {
                return null;
            }
        };
    }
}
