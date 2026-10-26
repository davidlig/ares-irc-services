<?php

declare(strict_types=1);

namespace App\Tests\Shared\Adapter\In\Irc\CommandArgument;

use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\CommandArgument\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Argument::class)]
#[CoversClass(CommandArgumentDefinition::class)]
#[CoversClass(CommandArgumentValidator::class)]
#[CoversClass(ValidationResult::class)]
final class CommandArgumentValidatorTest extends TestCase
{
    /** @return iterable<string, array{list<string>, bool, ?string}> */
    public static function contextualCases(): iterable
    {
        yield 'missing selector' => [[], true, 'set.syntax'];
        yield 'missing required tail' => [['PASSWORD'], true, 'set.password.syntax'];
        yield 'case insensitive literal' => [['password', 'long', 'password'], true, null];
        yield 'bare optional tail' => [['VHOST'], true, null];
        yield 'optional tail' => [['VHOST', 'example.test'], true, null];
        yield 'unknown selector stays local' => [['UNKNOWN'], false, null];
    }

    /** @param list<string> $args */
    #[DataProvider('contextualCases')]
    public function testSelectsContextualSyntaxWithoutChangingInput(array $args, bool $recognized, ?string $syntax): void
    {
        $definition = new CommandArgumentDefinition('set.syntax', [new Argument('option')], variants: [
            'PASSWORD' => new CommandArgumentDefinition('set.password.syntax', [new Argument('password', rest: true)]),
            'VHOST' => new CommandArgumentDefinition('set.vhost.syntax', [new Argument('vhost', optional: true, rest: true)]),
        ]);
        $before = $args;
        $result = CommandArgumentValidator::validate($definition, $args);
        self::assertSame($recognized, $result->recognized);
        self::assertSame($syntax, $result->syntaxKey);
        self::assertSame($recognized && null === $syntax, $result->isValid());
        self::assertSame($before, $args);
        self::assertSame(1, $definition->minimumArgs());
    }

    public function testPrefixDoesNotPreemptContextualPolicyChecks(): void
    {
        $definition = new CommandArgumentDefinition('set.syntax', [new Argument('channel'), new Argument('option')], variants: [
            'MLOCK' => new CommandArgumentDefinition('set.mlock.syntax', [new Argument('state', alternatives: ['ON', 'OFF'])], allowExtra: false),
        ]);
        self::assertSame('set.syntax', CommandArgumentValidator::validatePrefix($definition, ['#test'])->syntaxKey);
        self::assertTrue(CommandArgumentValidator::validatePrefix($definition, ['#test', 'MLOCK'])->isValid());
        self::assertSame('set.mlock.syntax', CommandArgumentValidator::validate($definition, ['#test', 'MLOCK'])->syntaxKey);
        self::assertSame('set.mlock.syntax', CommandArgumentValidator::validate($definition, ['#test', 'MLOCK', 'invalid'])->syntaxKey);
        self::assertSame('set.mlock.syntax', CommandArgumentValidator::validate($definition, ['#test', 'MLOCK', 'ON', 'extra'])->syntaxKey);
        self::assertTrue(CommandArgumentValidator::validate($definition, ['#test', 'MLOCK', 'off'])->isValid());
    }

    public function testSelectsNestedSyntaxAndPreservesLegacyExtraArguments(): void
    {
        $definition = new CommandArgumentDefinition('role.syntax', [new Argument('action')], variants: [
            'PERMS' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('role'), new Argument('action')], variants: [
                'ADD' => new CommandArgumentDefinition('role.perms.add.syntax', [new Argument('permission')], allowExtra: false),
            ]),
            'DEL' => new CommandArgumentDefinition('role.del.syntax', [new Argument('role')]),
        ]);
        self::assertSame('role.perms.syntax', CommandArgumentValidator::validate($definition, ['PERMS', 'staff'])->syntaxKey);
        self::assertSame('role.perms.add.syntax', CommandArgumentValidator::validate($definition, ['PERMS', 'staff', 'ADD'])->syntaxKey);
        self::assertTrue(CommandArgumentValidator::validate($definition, ['PERMS', 'staff', 'ADD', 'perm'])->isValid());
        self::assertFalse(CommandArgumentValidator::validate($definition, ['PERMS', 'staff', 'UNKNOWN'])->recognized);
        self::assertTrue(CommandArgumentValidator::validate($definition, ['DEL', 'staff', 'legacy'])->isValid());
    }

    public function testHandlesOptionalEmptyCommandsAndExactArgumentBounds(): void
    {
        $empty = new CommandArgumentDefinition('help.syntax', [new Argument('topic', optional: true, rest: true)]);
        self::assertSame(0, $empty->minimumArgs());
        self::assertTrue(CommandArgumentValidator::validate($empty, [])->isValid());
        self::assertTrue(CommandArgumentValidator::validate($empty, ['SET', 'PASSWORD'])->isValid());
        $exact = new CommandArgumentDefinition('exact.syntax', [new Argument('first'), new Argument('second', optional: true)], allowExtra: false);
        self::assertTrue(CommandArgumentValidator::validate($exact, ['one'])->isValid());
        self::assertTrue(CommandArgumentValidator::validate($exact, ['one', 'two'])->isValid());
        self::assertSame('exact.syntax', CommandArgumentValidator::validate($exact, ['one', 'two', 'three'])->syntaxKey);
        self::assertTrue(CommandArgumentValidator::validate(new CommandArgumentDefinition('empty.syntax'), [])->isValid());
    }
}
