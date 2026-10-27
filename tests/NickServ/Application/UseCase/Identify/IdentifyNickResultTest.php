<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Identify;

use App\NickServ\Application\UseCase\Identify\IdentifyNickResult;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifyNickResult::class)]
final class IdentifyNickResultTest extends TestCase
{
    #[Test]
    public function providesTheRequiredCanonicalNickname(): void
    {
        self::assertSame('RegisteredNick', IdentifyNickResult::success('RegisteredNick')->requireNickname());
        self::assertSame('ExistingNick', IdentifyNickResult::alreadyIdentified('ExistingNick')->requireNickname());
    }

    #[Test]
    public function rejectsRequiringANicknameFromAResultWithoutOne(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Identify result does not contain a nickname.');

        IdentifyNickResult::invalidCredentials()->requireNickname();
    }
}
