<?php

declare(strict_types=1);

namespace App\Application\NickServ\Set;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function in_array;

final readonly class SetMsgHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value): void
    {
        $flag = strtoupper($value);

        if (!in_array($flag, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.msg.syntax')]);

            return;
        }

        $account->switchMsg('ON' === $flag);
        $this->nickRepository->save($account);
        $context->reply('ON' === $flag ? 'set.msg.on' : 'set.msg.off');
    }
}
