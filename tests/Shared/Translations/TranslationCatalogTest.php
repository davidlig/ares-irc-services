<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Basic English catalog checks; loader behavior has its own coverage tests. */
#[CoversNothing]
final class TranslationCatalogTest extends TestCase
{
    #[Test]
    #[DataProvider('englishDomains')]
    public function englishCatalogCanBeLoaded(string $domain): void
    {
        self::assertNotEmpty(TranslationCatalog::parseFile(__DIR__ . '/../../../translations/' . $domain . '.en.yaml'));
    }

    /** @return iterable<string, array{string}> */
    public static function englishDomains(): iterable
    {
        foreach (['common', 'nickserv', 'chanserv', 'memoserv', 'operserv', 'mail'] as $domain) {
            yield $domain => [$domain];
        }
    }

    #[Test]
    public function englishReasonLabelsKeepTheDynamicReasonPlaceholder(): void
    {
        $catalog = TranslationCatalog::parseFile(__DIR__ . '/../../../translations/common.en.yaml');
        self::assertIsArray($catalog['debug']);
        self::assertSame('Reason: %reason%', $catalog['debug']['prefix_reason']);
        self::assertIsArray($catalog['label']);
        self::assertSame('No reason', $catalog['label']['no_reason']);
    }
}
