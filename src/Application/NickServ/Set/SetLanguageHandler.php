<?php

declare(strict_types=1);

namespace App\Application\NickServ\Set;

use App\Application\NickServ\Command\NickServContext;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use InvalidArgumentException;

final readonly class SetLanguageHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value): void
    {
        if ('' === $value) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.language.syntax')]);

            return;
        }

        try {
            $account->changeLanguage($value);
        } catch (InvalidArgumentException) {
            $context->reply('set.language.invalid', [
                'languages' => implode(', ', RegisteredNick::SUPPORTED_LANGUAGES),
            ]);

            return;
        }

        $this->nickRepository->save($account);
        $context->reply('set.language.success', ['language' => $account->getLanguage()]);
    }
}
