<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecure;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function strtoupper;
use function trim;

final readonly class SetSecureHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private ConfigureChannelSecureHandlerInterface $configureSecure,
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $normalized = strtoupper(trim($value));
        if ('ON' !== $normalized && 'OFF' !== $normalized) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.secure.syntax')]);

            return;
        }
        $on = 'ON' === $normalized;
        $this->configureSecure->handle(new ConfigureChannelSecure($channel, $on));
        $context->reply($on ? 'set.secure.on' : 'set.secure.off');

        $nick = $context->sender->nick ?? '';
        if ('' !== $nick) {
            $key = $on ? 'set.secure.notice_on' : 'set.secure.notice_off';
            $notice = $context->trans($key, ['%nickname%' => $nick]);
            $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
        }
    }
}
