<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Entity;

/**
 * Network-wide policy for e-mail verification during nickname registration.
 *
 * Absence of a row means "verification required": the default needs no seeding,
 * and the table is only written when an operator changes the policy.
 */
class EmailVerificationSetting
{
    private int $id;

    private bool $required;

    public function __construct(bool $required)
    {
        $this->required = $required;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function change(bool $required): void
    {
        $this->required = $required;
    }
}
