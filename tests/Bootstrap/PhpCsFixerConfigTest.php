<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet\RuleSet;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpCsFixerConfigTest extends TestCase
{
    #[Test]
    public function riskyFixersMustBeExplicitProjectConventions(): void
    {
        $config = require dirname(__DIR__, 2) . '/.php-cs-fixer.dist.php';
        $configured = $config->getRules();
        self::assertArrayNotHasKey('@Symfony:risky', $configured);
        self::assertArrayNotHasKey('@PHP8x5Migration:risky', $configured);
        self::assertTrue($configured['@Symfony']);
        self::assertTrue($configured['@PHP8x5Migration']);
        self::assertTrue($configured['declare_strict_types']);
        self::assertSame(['equal' => true, 'identical' => true, 'less_and_greater' => null], $configured['yoda_style']);
        $resolved = new RuleSet($configured)->getRules();
        foreach (new FixerFactory()->registerBuiltInFixers()->getFixers() as $fixer) {
            if ($fixer->isRisky() && isset($resolved[$fixer->getName()])) {
                self::assertArrayHasKey($fixer->getName(), $configured, 'Risky rules must not arrive through a preset.');
            }
        }
    }
}
