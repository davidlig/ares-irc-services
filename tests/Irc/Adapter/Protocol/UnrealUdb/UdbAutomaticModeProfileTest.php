<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAutomaticModeProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbAutomaticModeProfile::class)]
final class UdbAutomaticModeProfileTest extends TestCase
{
    public function testEncodesOnlySafeNativeSemanticsAndNeverRawLettersOrInvisible(): void
    {
        self::assertSame('+Bd', UdbAutomaticModeProfile::encode(['bot', 'invisible', 'channel-deaf', 'caller-id', 'o']));
        self::assertNull(UdbAutomaticModeProfile::encode([]));
        self::assertNull(UdbAutomaticModeProfile::encode(['invisible', 'caller-id']));
    }

    public function testKnownCapabilitiesKeepUnsupportedIntentionsDormantWithoutReinterpretingLetters(): void
    {
        self::assertSame('+d', UdbAutomaticModeProfile::encode(['bot', 'channel-deaf'], ['channel-deaf' => 'd']));
        self::assertNull(UdbAutomaticModeProfile::encode(['bot'], []));
        self::assertSame('+B', UdbAutomaticModeProfile::encode(['bot'], null));
    }
}
