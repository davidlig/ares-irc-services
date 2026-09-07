<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Port\Out\ChannelSuspensionNotifier;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ChanServSuspensionNoticeAdapter implements ChannelSuspensionNotifier
{
    public function __construct(
        private ChanServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage = 'en',
    ) {}

    public function notifyChannelSuspended(string $channelName, ?string $reason): void
    {
        $message = $this->translator->trans(
            'suspend.notice_channel',
            ['%reason%' => $reason ?? ''],
            'chanserv',
            $this->defaultLanguage,
        );
        $this->notifier->sendNoticeToChannel($channelName, $message);
    }
}
