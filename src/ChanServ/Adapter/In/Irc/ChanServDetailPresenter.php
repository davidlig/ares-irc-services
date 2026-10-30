<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;

use function is_string;
use function max;
use function mb_strlen;
use function str_repeat;

/** Presentation-only descriptors for localized channel detail rows. */
final readonly class ChanServDetailPresenter
{
    /** @param array<string, mixed> $parameters
     * @param array<string, Component> $fragments
     */
    public function row(ChanServContext $context, string $key, array $parameters = [], ?Component $valueStyle = null, array $fragments = []): string
    {
        $labelKey = $key . '_label';
        $label = $context->trans($labelKey);
        if ($labelKey === $label) {
            return $context->trans($key, $parameters);
        }

        foreach ($fragments as $name => $component) {
            if (isset($parameters[$name]) && is_string($parameters[$name])) {
                $parameters[$name] = new Fragment($parameters[$name], $component);
            }
        }
        $parameters['label'] = new Fragment($label, Component::Label);
        $parameters['padding'] = str_repeat(' ', max(0, 24 - mb_strlen($label)));
        if (null !== $valueStyle) {
            $parameters['state'] = new Fragment($context->trans($key . '_value'), $valueStyle);
        }

        return $context->renderComponent(Component::IndentedRow, $key, $parameters);
    }

    public function header(ChanServContext $context, string $key, string $channel): string
    {
        $template = $context->trans($key);
        if ($template === $key) {
            return $context->trans($key, ['channel' => $channel]);
        }

        return $context->renderTextComponent(Component::TableHeader, $template, [
            'channel' => new Fragment($channel, Component::Status),
        ]);
    }
}
