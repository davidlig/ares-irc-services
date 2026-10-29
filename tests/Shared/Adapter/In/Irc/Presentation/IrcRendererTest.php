<?php

declare(strict_types=1);

namespace App\Tests\Shared\Adapter\In\Irc\Presentation;

use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcRenderer::class)]
#[CoversClass(Appearance::class)]
#[CoversClass(Fragment::class)]
#[CoversClass(FixedStyleProvider::class)]
#[CoversClass(PresentationStyle::class)]
final class IrcRendererTest extends TestCase
{
    public function testSuccessAndErrorUseDifferentRolesForTheSameContent(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0303✔\x03\x0F Done", $renderer->reply(Role::Success, 'Done'));
        self::assertSame("\x0304✗\x03\x0F Done", $renderer->reply(Role::Error, 'Done'));
    }

    public function testStylesChangeWithoutChangingContentOrParameters(): void
    {
        $plain = new IrcRenderer(new FixedStyleProvider(new PresentationStyle()));
        $default = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $params = ['%channel%' => new Fragment('#ares', Component::Command)];
        self::assertSame('Registrado #ares', $plain->reply(Role::Success, 'Registrado %channel%', $params));
        self::assertSame("\x0303✔\x03\x0F Registrado \x02\x0303#ares\x03\x0F", $default->reply(Role::Success, 'Registrado %channel%', $params));
    }

    public function testLocalizedOrderIsPreservedAndParametersAreNotRecursivelyInterpolated(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle()));
        self::assertSame('2: %count%', $renderer->reply(Role::Information, '%count%: %channel%', ['%channel%' => '%count%', '%count%' => '2']));
    }

    public function testMultilineFragmentsResetAtEveryLineAndMarkerOccursOnce(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0303✔\x03\x0F \x02\x0303one\x03\x0F\n\x02\x0303two\x03\x0F\n\nEnd", $renderer->reply(Role::Success, "%value%\n\nEnd", ['%value%' => new Fragment("one\ntwo", Component::Command)]));
    }

    public function testHelpComponentsAndPlainTransportPrefix(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("  ◆ \x0310Group\x03\x0F", $renderer->component(Component::Group, 'Group'));
        self::assertSame("  /msg CustomBot \x02\x0303HELP SET\x03\x0F", $renderer->component(Component::Example, '/msg %bot% %command%', ['%bot%' => 'CustomBot', '%command%' => new Fragment('HELP SET', Component::Command)]));
        self::assertSame("\x1F\x03047 días\x03\x0F", $renderer->component(Component::Expiration, '7 días'));
    }

    public function testEmptyLinesAndPlainAppearanceHaveNoDecoration(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\n", $renderer->reply(Role::Warning, "\n"));
        self::assertSame(' ', $renderer->component(Component::Text, ' '));
        self::assertSame('', $renderer->component(Component::Text, ''));
    }

    public function testNestedFragmentRestoresTheEnclosingStyle(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("  ◆ \x0310Before \x03\x0F\x02\x0303HELP\x03\x0F\x0310 after\x03\x0F", $renderer->component(Component::Group, 'Before %command% after', ['%command%' => new Fragment('HELP', Component::Command)]));
    }

    public function testSeparatorAndUnreadDecorationBelongToTheStyle(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0314───\x03\x0F", $renderer->separator(3));
        self::assertSame("\x0304*\x03\x0F new", $renderer->component(Component::Unread, 'new'));
    }

    public function testMemoExampleMarginAndMarkerBelongToTheSelectedStyle(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("  \x0310›\x03\x0F /msg MemoBot \x02\x0303READ 2\x03\x0F", $renderer->component(Component::MarkedExample, '/msg MemoBot %command%', ['%command%' => new Fragment('READ 2', Component::Command)]));
        $plain = new IrcRenderer(new FixedStyleProvider(new PresentationStyle()));
        self::assertSame('/msg MemoBot READ 2', $plain->component(Component::MarkedExample, '/msg MemoBot %command%', ['%command%' => new Fragment('READ 2', Component::Command)]));
    }

    public function testDetailRowIndentationAndSeparatorBelongToTheStyle(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("  Extra \x0307→\x03\x0F value", $renderer->component(Component::IndentedRow, 'Extra %extra%', ['%extra%' => new Fragment('value', Component::DetailValue)]));
    }

    public function testDecoratedHeadersAndSelectionMarkersAreStyleOwned(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("--- \x02Title\x03\x0F ---\n\n--- \x02Next\x03\x0F ---", $renderer->component(Component::DecoratedHeader, "Title\n\nNext"));
        self::assertSame("\x0303✓\x03\x0F permission", $renderer->component(Component::Selected, 'permission'));
        self::assertSame("\x0304✗\x03\x0F permission", $renderer->component(Component::Unselected, 'permission'));
        self::assertSame('[title]', new Appearance(prefix: '[', suffix: ']')->apply('title'));
    }

    public function testOnlineStatusMarkerIsOwnedByTheStyle(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0303●\x03\x0F \x0303online\x03\x0F", $renderer->component(Component::Online, 'online'));
    }

    public function testExpirationNoticeDecoratesOnlyItsMarkerAndDuration(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0304⚠\x03\x0F NOTA: \x1F\x03047 días\x03\x0F restantes", $renderer->component(Component::ExpirationNotice, 'NOTA: %duration% restantes', ['%duration%' => new Fragment('7 días', Component::Expiration)]));
    }

    public function testBulletsAndIndentedEmphasisAreStyleOwned(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("• \x02TOKEN\x03\x0F", $renderer->component(Component::BulletedRow, 'TOKEN'));
        self::assertSame("  \x02es\x03\x0F", $renderer->component(Component::IndentedEmphasis, 'es'));
    }

    public function testNestedFragmentsRenderIndentedRowsWithoutReinterpolatingUserValues(): void
    {
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        self::assertSame("\x0303✔\x03\x0F Done\n  /msg Bot \x02\x0303VERIFY %token%\x03\x0F", $renderer->reply(Role::Success, "Done\n%row%", [
            '%row%' => new Fragment('/msg Bot %command%', Component::IndentedRow, [
                '%command%' => new Fragment('VERIFY %token%', Component::Command),
            ]),
            '%token%' => 'must not expand',
        ]));
    }
}
