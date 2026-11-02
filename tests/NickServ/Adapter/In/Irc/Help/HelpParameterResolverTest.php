<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Help;

use App\NickServ\Adapter\In\Irc\Help\HelpParameterResolver;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HelpParameterResolver::class)]
final class HelpParameterResolverTest extends TestCase
{
    #[Test]
    public function resolvesUnfamiliarKeysWithoutInterpretingLiteralValues(): void
    {
        $parameters = [
            'arbitrary' => ['value' => '%untrusted%', 'component' => Component::Emphasis],
            'localized' => ['value' => 'custom.syntax', 'component' => Component::Command, 'translate' => true],
            'duration_seconds' => 120,
            'ordinary_array' => ['value' => 'data'],
            'wrong_component' => ['value' => 'data', 'component' => 'command'],
            'wrong_value' => ['value' => 42, 'component' => Component::Command],
            'already_styled' => new Fragment('existing'),
        ];
        $calls = [];
        $translate = static function (string $key) use (&$calls): string {
            $calls[] = $key;

            return '/CUSTOM <argument>';
        };
        $result = new HelpParameterResolver()->resolve($parameters, $translate, true);

        self::assertInstanceOf(Fragment::class, $result['arbitrary']);
        self::assertSame('%untrusted%', $result['arbitrary']->text);
        self::assertSame(Component::Emphasis, $result['arbitrary']->component);
        self::assertInstanceOf(Fragment::class, $result['localized']);
        self::assertSame('/CUSTOM <argument>', $result['localized']->text);
        self::assertSame(Component::Command, $result['localized']->component);
        foreach (['duration_seconds', 'ordinary_array', 'wrong_component', 'wrong_value', 'already_styled'] as $name) {
            self::assertSame($parameters[$name], $result[$name]);
        }
        self::assertSame(['custom.syntax'], $calls);
    }

    #[Test]
    public function returnsPlainParametersWithoutPresentation(): void
    {
        $parameters = [
            'literal' => ['value' => 'PRIVATE ON', 'component' => Component::Emphasis],
            'localized' => ['value' => 'custom.syntax', 'component' => Component::Command, 'translate' => true],
        ];

        self::assertSame(
            ['literal' => 'PRIVATE ON', 'localized' => '/CUSTOM <argument>'],
            new HelpParameterResolver()->resolve($parameters, static fn (string $key): string => '/CUSTOM <argument>', false),
        );
    }
}
