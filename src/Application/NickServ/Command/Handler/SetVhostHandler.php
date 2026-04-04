<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function in_array;
use function strtoupper;
use function trim;

final readonly class SetVhostHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly VhostValidator $vhostValidator,
        private readonly VhostDisplayResolver $displayResolver,
        private readonly NetworkUserLookupPort $userLookup,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        $normalized = trim($value);
        $clearKeywords = ['OFF', ''];
        if ('' === $normalized || in_array(strtoupper($normalized), $clearKeywords, true)) {
            $account->changeVhost(null);
            $this->nickRepository->save($account);

            // In IRCop mode, the target user may be different from the sender
            if ($isIrcopMode) {
                $targetUser = $this->userLookup->findByNick($account->getNickname());
                if (null !== $targetUser) {
                    $context->getNotifier()->setUserVhost($targetUser->uid, '', $targetUser->serverSid);
                }
            } elseif (null !== $context->sender) {
                $context->getNotifier()->setUserVhost($context->sender->uid, '', $context->sender->serverSid);
            }
            $context->reply('set.vhost.cleared');

            return;
        }

        $normalized = $this->vhostValidator->normalize($normalized);
        if (null === $normalized) {
            $context->reply('set.vhost.invalid');

            return;
        }

        $existing = $this->nickRepository->findByVhost($normalized);
        if (null !== $existing && $existing->getId() !== $account->getId()) {
            $context->reply('set.vhost.taken');

            return;
        }

        $account->changeVhost($normalized);
        $this->nickRepository->save($account);

        $displayVhost = $this->displayResolver->getDisplayVhost($normalized);

        // In IRCop mode, the target user may be different from the sender
        if ($isIrcopMode) {
            $targetUser = $this->userLookup->findByNick($account->getNickname());
            if (null !== $targetUser && '' !== $displayVhost) {
                $context->getNotifier()->setUserVhost($targetUser->uid, $displayVhost, $targetUser->serverSid);
            }
        } elseif (null !== $context->sender && '' !== $displayVhost) {
            $context->getNotifier()->setUserVhost($context->sender->uid, $displayVhost, $context->sender->serverSid);
        }
        $context->reply('set.vhost.success', ['vhost' => $displayVhost]);
    }
}
