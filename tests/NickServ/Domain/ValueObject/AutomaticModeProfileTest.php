<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\ValueObject;

use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomaticModeProfile::class)]
final class AutomaticModeProfileTest extends TestCase
{
    public function testNormalizesSemanticIntentionsAndEmptyProfiles(): void
    {
        self::assertSame(['bot', 'invisible'], new AutomaticModeProfile(['invisible', 'bot', 'bot'])->intentions);
        self::assertSame([], new AutomaticModeProfile()->intentions);
    }

    public function testRejectsRawLettersAndUnknownIntentions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AutomaticModeProfile(['o']);
    }
}
