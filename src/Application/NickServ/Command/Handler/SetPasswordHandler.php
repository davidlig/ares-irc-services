<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

final readonly class SetPasswordHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.password.syntax')]);

            return;
        }

        $account->changePasswordWithHasher($value, $this->passwordHasher);
        $this->nickRepository->save($account);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickPasswordChangedEvent(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            changedByOwner: !$isIrcopMode,
            performedBy: $context->sender->nick,
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
