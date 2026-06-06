<?php

declare(strict_types=1);

namespace App\Infrastructure\Translation;

use App\Application\Port\TranslationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SymfonyTranslationAdapter implements TranslationInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}
