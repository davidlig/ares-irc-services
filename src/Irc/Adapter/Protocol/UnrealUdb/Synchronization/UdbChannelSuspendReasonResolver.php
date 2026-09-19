<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the C::<#channel>::suspend reason for channels pending deletion.
 *
 * The UDB module shows the stored value to every local user who joins the
 * channel, so the reason is translated for the configured default language.
 */
final readonly class UdbChannelSuspendReasonResolver
{
    public function __construct(
        private TranslatorInterface $translator,
        private string $chanservNick,
        private string $defaultLanguage = 'en',
    ) {}

    public function pendingDeletionReason(string $channelName): string
    {
        return $this->translator->trans(
            'drop.pending_deletion_reason',
            ['%channel%' => $channelName, '%bot%' => $this->chanservNick],
            'chanserv',
            $this->defaultLanguage,
        );
    }
}
