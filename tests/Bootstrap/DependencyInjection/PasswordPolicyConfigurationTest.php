<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\DependencyInjection;

use App\Kernel;
use App\NickServ\Adapter\Out\Random\SecureRecoveryPasswordGenerator;
use App\NickServ\Application\Service\PasswordPolicy;
use Exception;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

use function ctype_digit;

#[CoversNothing]
final class PasswordPolicyConfigurationTest extends TestCase
{
    #[DataProvider('configuration')]
    public function testCompiledDefaultsAndInvalidConfiguration(?string $minimum, ?string $complexity, bool $valid): void
    {
        $oldEnv = $_ENV;
        $oldServer = $_SERVER;
        foreach (['NICKSERV_PASSWORD_MIN_LENGTH' => $minimum, 'NICKSERV_PASSWORD_COMPLEXITY' => $complexity] as $key => $value) {
            unset($_ENV[$key], $_SERVER[$key]);
            if (null !== $value) {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(TestContainer::class, $container);
            if (!$valid) {
                $this->expectException(Exception::class);
                $this->expectExceptionMessage(null !== $minimum && (!ctype_digit($minimum) || 1 > (int) $minimum) ? 'NICKSERV_PASSWORD_MIN_LENGTH' : 'NICKSERV_PASSWORD_COMPLEXITY');
            }
            $policy = $container->get(PasswordPolicy::class);
            self::assertInstanceOf(PasswordPolicy::class, $policy);
            self::assertSame(null === $minimum ? 8 : (int) $minimum, $policy->minimumLength);
            self::assertSame($complexity ?? 'none', $policy->complexity);
            $generator = $container->get(SecureRecoveryPasswordGenerator::class);
            self::assertInstanceOf(SecureRecoveryPasswordGenerator::class, $generator);
            self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', $generator->generate());
        } finally {
            $kernel->shutdown();
            $_ENV = $oldEnv;
            $_SERVER = $oldServer;
        }
    }

    /** @return iterable<array{?string, ?string, bool}> */
    public static function configuration(): iterable
    {
        yield [null, null, true];
        yield ['19', 'high', true];
        yield ['8', 'low', true];
        yield ['8', 'mid', true];
        yield ['8', 'none', false];
        yield ['0', 'none', false];
        yield ['-1', 'none', false];
        yield ['1.5', 'none', false];
        yield ['', 'none', false];
        yield ['8', '', false];
        yield ['8', 'HIGH', false];
        yield ['8', 'invalid', false];
    }
}
