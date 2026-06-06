<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TranslationInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
}
