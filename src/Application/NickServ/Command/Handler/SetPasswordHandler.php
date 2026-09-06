<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServContext;
use App\Application\Port\EventBusInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;

use function sprintf;

final readonly class SetPasswordHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasherInterface $passwordHasher,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.password.syntax')]);

            return;
        }

        $account->changePasswordWithHasher($value, $this->passwordHasher);
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
