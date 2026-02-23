<?php

declare(strict_types=1);

namespace App\Application\NickServ\Set;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function in_array;
use function strlen;

final readonly class SetVhostHandler implements SetOptionHandlerInterface
{
    /** Max length for vhost (IRCD/DB limit). Allowed: hostname-like (letters, digits, hyphens, dots). */
    private const int VHOST_MAX_LENGTH = 255;

    private const string VHOST_PATTERN = '/^[a-zA-Z0-9.\-]+$/';

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value): void
    {
        $normalized = trim($value);
        $clearKeywords = ['OFF', ''];
        if ('' === $normalized || in_array(strtoupper($normalized), $clearKeywords, true)) {
            $account->changeVhost(null);
            $this->nickRepository->save($account);
            if (null !== $context->sender) {
                $context->getNotifier()->setUserVhost($context->sender->uid->value, '');
            }
            $context->reply('set.vhost.cleared');

            return;
        }

        if (strlen($normalized) > self::VHOST_MAX_LENGTH || 1 !== preg_match(self::VHOST_PATTERN, $normalized)) {
            $context->reply('set.vhost.invalid');

            return;
        }

        $account->changeVhost($normalized);
        $this->nickRepository->save($account);
        if (null !== $context->sender) {
            $context->getNotifier()->setUserVhost($context->sender->uid->value, $normalized);
        }
        $context->reply('set.vhost.success', ['vhost' => $normalized]);
    }
}
