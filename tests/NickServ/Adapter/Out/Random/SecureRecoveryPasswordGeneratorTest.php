<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Random;

use App\NickServ\Adapter\Out\Random\SecureRecoveryPasswordGenerator;
use App\NickServ\Application\Service\PasswordPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function max;
use function strlen;

#[CoversClass(SecureRecoveryPasswordGenerator::class)]
final class SecureRecoveryPasswordGeneratorTest extends TestCase
{
    #[Test]
    public function generatesTwelvePortableCharacters(): void
    {
        $generator = new SecureRecoveryPasswordGenerator();
        $password = $generator->generate();

        self::assertSame(12, strlen($password));
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9!@#$%&*+_=.?-]+$/D', $password);
    }

    #[DataProvider('policies')]
    public function testMeetsPolicy(int $minimum, string $complexity): void
    {
        $policy = new PasswordPolicy($minimum, $complexity);
        $generator = new SecureRecoveryPasswordGenerator($policy);
        for ($i = 0; $i < 20; ++$i) {
            $password = $generator->generate();
            self::assertSame(max(12, $minimum), strlen($password));
            self::assertTrue($policy->isSatisfiedBy($password));
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9!@#$%&*+_=.?-]+$/D', $password);
        }
    }

    /** @return iterable<array{int, string}> */
    public static function policies(): iterable
    {
        foreach (['none', 'low', 'mid', 'high'] as $complexity) {
            yield [1, $complexity];
            yield [24, $complexity];
        }
    }
}
