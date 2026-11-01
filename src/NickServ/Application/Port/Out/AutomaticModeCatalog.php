<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface AutomaticModeCatalog
{
    /** @return array<string,string>|null Semantic intention => current letter; null when unknown. */
    public function available(): ?array;
}
