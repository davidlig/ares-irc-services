<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\NojoinActions;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IrcNojoinActions implements NojoinActions
{
    public function __construct(
        private ChannelServiceActionsPort $networkActions,
        private TranslatorInterface $translator,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function kick(
        string $channelName,
        string $uid,
        string $nickname,
        int $effectiveAccess,
        int $requiredAccess,
        string $language,
    ): void {
        $reason = $this->translator->trans('nojoin.reason', [], 'chanserv', $language);
        $this->networkActions->kickFromChannel($channelName, $uid, $reason);

        $this->logger->info('NOJOIN enforced', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $nickname,
            'userLevel' => $effectiveAccess,
            'nojoinLevel' => $requiredAccess,
        ]);
    }
}
