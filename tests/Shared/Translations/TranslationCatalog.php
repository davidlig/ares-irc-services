<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use RuntimeException;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

use function is_array;

/** Keeps source mapping structure while resolving messages through the production loader. */
final class TranslationCatalog
{
    /** @return array<array-key, mixed> */
    public static function parseFile(string $file): array
    {
        $source = Yaml::parseFile($file, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        if (!is_array($source)) {
            throw new RuntimeException('Translation catalog must be a mapping.');
        }

        return self::resolve($source, new IrcYamlTranslationLoader()->load($file, 'en'));
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>
     */
    private static function resolve(array $source, MessageCatalogueInterface $catalogue, string $prefix = ''): array
    {
        foreach ($source as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ($value instanceof TaggedValue) {
                $source[$key] = $catalogue->get($path);
            } elseif (is_array($value)) {
                $source[$key] = self::resolve($value, $catalogue, $path);
            }
        }

        return $source;
    }
}
