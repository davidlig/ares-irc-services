<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickPasswordChangedEvent;

use function sprintf;

final readonly class SetPasswordHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasher $passwordHasher,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.password.syntax')]);

            return;
        }

        $account->changePassword($this->passwordHasher->hash($value));
        $this->nickRepository->save($account);

        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickPasswordHashAvailable(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            passwordHash: $account->getPasswordHash(),
        ));

        $this->eventDispatcher->dispatch(new NickPasswordChangedEvent(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            changedByOwner: !$isIrcopMode,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('set.password.success');
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
