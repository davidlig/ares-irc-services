<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use InvalidArgumentException;

final readonly class SetLanguageHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
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
