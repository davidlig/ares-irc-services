<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use InvalidArgumentException;

final readonly class SetTimezoneHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        $value = trim($value);

        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.timezone.syntax')]);

            return;
        }

        if (0 === strcasecmp($value, 'OFF')) {
            $account->changeTimezone(null);
            $this->nickRepository->save($account);
            $context->reply('set.timezone.cleared');

            return;
        }

        try {
            $account->changeTimezone($value);
        } catch (InvalidArgumentException) {
            $context->reply('set.timezone.invalid', ['timezone' => $value]);

            return;
        }

        $this->nickRepository->save($account);
        $context->reply('set.timezone.success', ['timezone' => $account->getTimezone()]);
    }
}
