<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Entity;

use App\ChanServ\Domain\ValueObject\RegistrationMode;

final class RegistrationPolicy
{
    private int $id = 1;

    public function __construct(private RegistrationMode $mode = RegistrationMode::Direct) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getMode(): RegistrationMode
    {
        return $this->mode;
    }

    public function changeMode(RegistrationMode $mode): void
    {
        $this->mode = $mode;
    }
}
