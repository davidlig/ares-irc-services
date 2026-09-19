<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Random;

use App\NickServ\Adapter\Out\Random\SecureGuestNicknameGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecureGuestNicknameGenerator::class)]
final class SecureGuestNicknameGeneratorTest extends TestCase
{
    #[Test]
    public function generatesAnUppercaseRandomSuffixAfterTheConfiguredPrefix(): void
    {
        $nickname = new SecureGuestNicknameGenerator()->generate('Guest-');

        self::assertMatchesRegularExpression('/^Guest-[0-9A-F]{7}$/', $nickname);
    }
}
