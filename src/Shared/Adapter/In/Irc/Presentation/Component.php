<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

enum Component: string
{
    case Text = 'text';
    case Positive = 'positive';
    case Danger = 'danger';
    case Label = 'label';
    case Muted = 'muted';
    case Actor = 'actor';
    case ModeChange = 'mode_change';
    case Target = 'target';
    case ChannelTag = 'channel_tag';
    case TableHeader = 'table_header';
    case DecoratedHeader = 'decorated_header';
    case Selected = 'selected';
    case Unselected = 'unselected';
    case Row = 'row';
    case IndentedRow = 'indented_row';
    case BulletedRow = 'bulleted_row';
    case IndentedEmphasis = 'indented_emphasis';
    case DetailValue = 'detail_value';
    case Status = 'status';
    case Online = 'online';
    case Unread = 'unread';
    case Emphasis = 'emphasis';
    case Header = 'header';
    case Section = 'section';
    case Group = 'group';
    case Command = 'command';
    case CommandRow = 'command_row';
    case Navigation = 'navigation';
    case Syntax = 'syntax';
    case Example = 'example';
    case MarkedExample = 'marked_example';
    case Separator = 'separator';
    case Expiration = 'expiration';
    case ExpirationNotice = 'expiration_notice';
}
