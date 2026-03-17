<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

use DateTimeImmutable;

class OperIrcop
{
    private int $id;

    private int $nickId;

    private OperRole $role;

    private DateTimeImmutable $addedAt;

    private ?int $addedById = null;

    private ?string $reason = null;

    public static function create(
        int $nickId,
        OperRole $role,
        ?int $addedById = null,
        ?string $reason = null,
    ): self {
        $admin = new self();
        $admin->nickId = $nickId;
        $admin->role = $role;
        $admin->addedAt = new DateTimeImmutable();
        $admin->addedById = $addedById;
        $admin->reason = $reason;

        return $admin;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNickId(): int
    {
        return $this->nickId;
    }

    public function getRole(): OperRole
    {
        return $this->role;
    }

    public function changeRole(OperRole $role): void
    {
        $this->role = $role;
    }

    public function getAddedAt(): DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedById(): ?int
    {
        return $this->addedById;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }
}
