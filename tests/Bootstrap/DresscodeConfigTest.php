<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use Nette\Neon\Neon;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

#[CoversNothing]
final class DresscodeConfigTest extends TestCase
{
    #[Test]
    public function keepsTheProjectConventionsWithoutEnablingAllRiskyFixes(): void
    {
        $path = dirname(__DIR__, 2) . '/dresscode.neon';
        self::assertFileExists($path);
        $config = Neon::decodeFile($path);
        self::assertIsArray($config);

        $standards = $config['use'];
        if ('symfony' !== $standards) {
            self::assertIsArray($standards);
            self::assertContains('symfony', $standards);
        }

        self::assertSame(['strictTypes' => ['declaration' => 'required']], $config['file']);

        $expressions = $config['expressions'];
        self::assertIsArray($expressions);
        self::assertSame(['yoda' => 'required'], $expressions['comparison']);

        $risky = $config['fixRisky'] ?? [];
        self::assertIsArray($risky, 'Only named risky fixes may be enabled.');
        self::assertContainsOnlyString($risky);
    }
}
