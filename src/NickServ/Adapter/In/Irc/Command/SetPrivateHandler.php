<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;

use function in_array;

final readonly class SetPrivateHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        $flag = strtoupper($value);

        if (!in_array($flag, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.private.syntax')]);

            return;
        }

        $account->switchPrivate('ON' === $flag);
        $this->nickRepository->save($account);
        $context->reply('ON' === $flag ? 'set.private.on' : 'set.private.off');
    }
}
