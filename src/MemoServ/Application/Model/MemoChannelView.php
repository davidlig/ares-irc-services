<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Model;

final readonly class MemoChannelView
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
