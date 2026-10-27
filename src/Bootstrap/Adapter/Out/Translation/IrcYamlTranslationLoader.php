<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Translation;

use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

use function is_array;
use function is_string;
use function sprintf;
use function str_ireplace;

/** Decodes explicitly tagged IRC controls before translation parameters are interpolated. */
final class IrcYamlTranslationLoader extends YamlFileLoader
{
    /** @return array<array-key, mixed> */
    protected function loadResource(string $resource): array
    {
        try {
            $messages = Yaml::parseFile($resource, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $exception) {
            throw new InvalidResourceException(sprintf('The file "%s" does not contain valid YAML: ', $resource) . $exception->getMessage(), 0, $exception);
        }

        if (null !== $messages && !is_array($messages)) {
            throw new InvalidResourceException(sprintf('Unable to load file "%s".', $resource));
        }

        return $this->decodeMessages($messages ?? [], $resource);
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return array<array-key, mixed>
     */
    private function decodeMessages(array $messages, string $resource): array
    {
        foreach ($messages as $key => $value) {
            if ($value instanceof TaggedValue) {
                if ('irc' !== $value->getTag() || !is_string($value->getValue())) {
                    throw new InvalidResourceException(sprintf('Invalid translation tag "%s" in "%s": expected !irc with a string value.', $value->getTag(), $resource));
                }

                $messages[$key] = str_ireplace(['\\x02', '\\x03', '\\x0f'], ["\x02", "\x03", "\x0f"], $value->getValue());
            } elseif (is_array($value)) {
                $messages[$key] = $this->decodeMessages($value, $resource);
            }
        }

        return $messages;
    }
}
