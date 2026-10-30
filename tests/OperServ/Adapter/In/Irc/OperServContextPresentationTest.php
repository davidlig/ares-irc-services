<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function strtr;

#[CoversClass(OperServContext::class)]
final class OperServContextPresentationTest extends TestCase
{
    #[Test]
    public function presentationMethodsSupportStyledAndLegacyContexts(): void
    {
        $sent = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$sent): void { $sent[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params): string {
            $templates = ['presentation.identify' => 'IDENTIFY', 'presentation.error.not_identified' => 'Use %identify% for %nickname% (%count%)', 'row' => '%value%', 'role.perms.list.assigned' => 'yes', 'role.perms.list.unassigned' => 'no'];

            return strtr($templates[$id] ?? $id, $params);
        });
        $style = new PresentationStyle(markers: ['error' => '!'], components: [
            'command' => new Appearance(prefix: '[', suffix: ']'), 'emphasis' => new Appearance(prefix: '<', suffix: '>'),
            'row' => new Appearance(prefix: 'R:'), 'selected' => new Appearance(prefix: '+'), 'unselected' => new Appearance(prefix: '-'),
        ]);
        $renderer = new IrcRenderer(new FixedStyleProvider($style));
        $make = fn (?IrcRenderer $selected): OperServContext => new OperServContext(new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', new OperServCommandRegistry([]), new ServiceNicknameRegistry([]), $this->createStub(OperatorAuthorizationQuery::class), $selected);
        $styled = $make($renderer);
        $styled->replyError('error.not_identified', ['nickname' => 'Bob', 'count' => 2]);
        $styled->replyComponent(Component::Row, 'row', ['value' => $styled->commandFragment('ROLE')]);
        self::assertSame("! Use [IDENTIFY] for <Bob> (2)\nR:[ROLE]", implode("\n", $sent));
        self::assertInstanceOf(Fragment::class, $styled->emphasize('Bob'));
        self::assertInstanceOf(Fragment::class, $styled->fragment('Bob', Component::Actor));
        self::assertInstanceOf(Fragment::class, $styled->danger('danger'));
        self::assertSame('+', $styled->selectedStatus(true));
        self::assertSame('-', $styled->selectedStatus(false));
        self::assertSame('R:hello', $styled->renderComponent(Component::Row, 'hello'));
        self::assertSame($renderer->separator(3), $styled->separator(3));
        $legacy = $make(null);
        $legacy->replyComponent(Component::Row, 'row', ['value' => 'plain']);
        self::assertSame('plain', $sent[2]);
        self::assertSame('ROLE', $legacy->commandFragment('ROLE'));
        self::assertSame('Bob', $legacy->emphasize('Bob'));
        self::assertSame('Bob', $legacy->fragment('Bob', Component::Actor));
        self::assertSame('yes', $legacy->selectedStatus(true));
        self::assertSame('no', $legacy->selectedStatus(false));
        self::assertNotSame('', $legacy->danger('danger'));
        self::assertNotSame('', $legacy->renderComponent(Component::Row, 'hello'));
        self::assertNotSame('', $legacy->separator(3));
        $beforeInvalid = implode("\n", $sent);
        try {
            $styled->replyComponent(Component::Row, 'row', ['value' => ['invalid']]);
            self::fail('A non-text presentation parameter must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('IRC presentation parameters must be text or fragments.', $exception->getMessage());
        }
        self::assertSame($beforeInvalid, implode("\n", $sent));
    }

    #[Test]
    public function rendersSharedErrorWithCommandSyntaxFragment(): void
    {
        $sent = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$sent): void { $sent[] = $message; });
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['presentation' => ['error.syntax' => 'Usage %syntax%']], 'en', 'common');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['error' => '!'])));
        $context = new OperServContext(
            new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'RAW',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
            $renderer
        );
        $context->replyError('error.syntax', ['%syntax%' => 'RAW <line>']);

        self::assertSame(['! Usage RAW <line>'], $sent);
    }

    #[Test]
    public function rendersExplicitRoleAndPreservesRawParameterContent(): void
    {
        $sent = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$sent): void {
            $sent[] = [$uid, $message, $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('%message% for %name%');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['error' => '!'])));
        $context = new OperServContext(
            new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
            $renderer,
        );

        $context->reply('test', ['name' => 'Bob', 'message' => '%name%'], Role::Error);

        self::assertSame([['UID', '! %name% for Bob', 'NOTICE']], $sent);
    }
}
