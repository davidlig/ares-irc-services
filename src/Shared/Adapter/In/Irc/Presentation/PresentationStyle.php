<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

final readonly class PresentationStyle
{
    /**
     * @param array<string, Appearance> $components
     * @param array<string, string>     $markers
     */
    public function __construct(private array $components = [], private array $markers = [], private string $separator = '-') {}

    public function appearance(Component $component): Appearance
    {
        return $this->components[$component->value] ?? new Appearance();
    }

    public function marker(Role $role): string
    {
        return $this->markers[$role->value] ?? '';
    }

    public function separator(): string
    {
        return $this->separator;
    }

    public static function default(): self
    {
        return new self([
            Component::Positive->value => new Appearance('03'),
            Component::Danger->value => new Appearance('04'),
            Component::Label->value => new Appearance('14'),
            Component::Muted->value => new Appearance('14'),
            Component::Actor->value => new Appearance('02'),
            Component::ModeChange->value => new Appearance('04'),
            Component::Target->value => new Appearance('14'),
            Component::ChannelTag->value => new Appearance('03', prefix: '[', suffix: ']'),
            Component::TableHeader->value => new Appearance(bold: true),
            Component::DecoratedHeader->value => new Appearance(bold: true, prefix: '--- ', suffix: ' ---'),
            Component::Selected->value => new Appearance(prefix: "\x0303✓\x03\x0F "),
            Component::Unselected->value => new Appearance(prefix: "\x0304✗\x03\x0F "),
            Component::BulletedRow->value => new Appearance(bold: true, prefix: '• '),
            Component::IndentedEmphasis->value => new Appearance(bold: true, prefix: '  '),
            Component::IndentedRow->value => new Appearance(prefix: '  '),
            Component::DetailValue->value => new Appearance(prefix: "\x0307→\x03\x0F "),
            Component::Status->value => new Appearance('07'),
            Component::Online->value => new Appearance('03', prefix: "\x0303●\x03\x0F "),
            Component::Unread->value => new Appearance(prefix: "\x0304*\x03\x0F "),
            Component::Emphasis->value => new Appearance(bold: true),
            Component::Header->value => new Appearance('07', bold: true, prefix: '🤖 '),
            Component::Section->value => new Appearance(bold: true, prefix: ' ■ '),
            Component::Group->value => new Appearance('10', prefix: '  ◆ '),
            Component::Command->value => new Appearance('03', bold: true),
            Component::CommandRow->value => new Appearance(prefix: "    \x0310›\x03\x0F "),
            Component::Navigation->value => new Appearance(prefix: "\x0310ℹ\x03\x0F "),
            Component::Example->value => new Appearance(prefix: '  '),
            Component::MarkedExample->value => new Appearance(prefix: "  \x0310›\x03\x0F "),
            Component::Separator->value => new Appearance('14'),
            Component::ExpirationNotice->value => new Appearance(prefix: "\x0304⚠\x03\x0F "),
            Component::Expiration->value => new Appearance('04', underline: true),
        ], [
            Role::Success->value => "\x0303✔\x03\x0F",
            Role::Error->value => "\x0304✗\x03\x0F",
            Role::Warning->value => "\x0307⚠\x03\x0F",
            Role::Information->value => "\x0310ℹ\x03\x0F",
        ], '─');
    }
}
