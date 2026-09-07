<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\NickServ;

use App\MemoServ\Adapter\Out\NickServ\NickServUserPreferencesAdapter;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServUserPreferencesAdapter::class)]
final class NickServUserPreferencesAdapterTest extends TestCase
{
    #[Test]
    public function languageForResolvesByNicknameWithoutAccountLanguage(): void
    {
        $languageQuery = $this->createMock(UserLanguageQuery::class);
        $languageQuery->expects(self::once())->method('resolve')->with('001AAAAAA', 'Alice')->willReturn('es');
        $languageQuery->expects(self::never())->method('resolveFromAccount');

        $adapter = new NickServUserPreferencesAdapter(
            $languageQuery,
            $this->createStub(UserMessagePreferenceQuery::class),
        );

        self::assertSame('es', $adapter->languageFor('001AAAAAA', 'Alice'));
    }

    #[Test]
    public function languageForResolvesProvidedAccountLanguage(): void
    {
        $languageQuery = $this->createMock(UserLanguageQuery::class);
        $languageQuery->expects(self::never())->method('resolve');
        $languageQuery->expects(self::once())->method('resolveFromAccount')->with('001AAAAAA', 'fr')->willReturn('fr');

        $adapter = new NickServUserPreferencesAdapter(
            $languageQuery,
            $this->createStub(UserMessagePreferenceQuery::class),
        );

        self::assertSame('fr', $adapter->languageFor('001AAAAAA', 'IgnoredNickname', 'fr'));
    }

    #[Test]
    public function privateMessagePreferenceDelegatesToNickServQuery(): void
    {
        $messagePreferenceQuery = $this->createMock(UserMessagePreferenceQuery::class);
        $messagePreferenceQuery->expects(self::once())->method('prefersPrivateMessages')->with('Alice')->willReturn(true);

        $adapter = new NickServUserPreferencesAdapter(
            $this->createStub(UserLanguageQuery::class),
            $messagePreferenceQuery,
        );

        self::assertTrue($adapter->prefersPrivateMessages('Alice'));
    }
}
