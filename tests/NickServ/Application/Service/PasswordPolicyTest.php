<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Application\Service\PasswordPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_split;

#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    #[DataProvider('passwords')]
    public function testPolicy(string $complexity, string $password, bool $accepted, int $minimum = 8): void
    {
        self::assertSame($accepted, new PasswordPolicy($minimum, $complexity)->isSatisfiedBy($password));
    }

    /** @return iterable<array{string, string, bool, 3?: int}> */
    public static function passwords(): iterable
    {
        yield ['none', 'abcdefg', false];
        yield ['none', 'abcdefgh', true];
        yield ['none', 'abcdefghijkl', true];
        yield ['none', 'abcdefgh', false, 9];
        yield ['none', 'áéíóúñüç', true];
        yield ['none', 'áéíóúñü', false];
        yield ['none', '  abcdef', true];
        yield ['low', 'abcdefg1', true];
        yield ['low', 'ABCDEFG1', true];
        yield ['low', 'abcdefgh', false];
        yield ['low', '12345678', false];
        yield ['low', 'ñññññññ1', false];
        yield ['mid', 'Abcdefg1', true];
        yield ['mid', 'abcdefg1', false];
        yield ['mid', 'ABCDEFG1', false];
        yield ['mid', 'Abcdefgh', false];
        yield ['high', 'Abcdefg1', false];
        yield ['high', 'Abcdef1 ', false];
        yield ['high', "Abcdef1\x03", false];
        yield ['high', 'Abcdef1é', false];
        yield ['high', 'Ab1!', false];
        yield ['high', 'Abcdef1!ñ', true];
        foreach (str_split('!@#$%&*+-_=.?') as $symbol) {
            yield ['high', 'Abcdef1' . $symbol, true];
        }
    }

    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(int $minimum, string $complexity): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PasswordPolicy($minimum, $complexity);
    }

    /** @return iterable<array{int, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield [0, 'none'];
        yield [-1, 'none'];
        yield [8, 'HIGH'];
        yield [8, ''];
        yield [8, 'unknown'];
    }
}
