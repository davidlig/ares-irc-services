<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\NickServ;

use App\CRegServ\Adapter\Out\NickServ\NickServCRegServUserPreferencesAdapter;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServCRegServUserPreferencesAdapter::class)]
final class NickServCRegServUserPreferencesAdapterTest extends TestCase
{
    #[Test]
    public function languageForResolvesByNicknameWithoutAccountLanguage(): void
    {
        $languageQuery = $this->createMock(UserLanguageQuery::class);
        $languageQuery->expects(self::once())->method('resolve')->with('001AAAAAA', 'Alice')->willReturn('es');
        $languageQuery->expects(self::never())->method('resolveFromAccount');

        $adapter = new NickServCRegServUserPreferencesAdapter(
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

        $adapter = new NickServCRegServUserPreferencesAdapter(
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

        $adapter = new NickServCRegServUserPreferencesAdapter(
            $this->createStub(UserLanguageQuery::class),
            $messagePreferenceQuery,
        );

        self::assertTrue($adapter->prefersPrivateMessages('Alice'));
    }
}
