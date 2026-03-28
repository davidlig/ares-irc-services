<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use function is_array;

class OperRole
{
    private int $id;

    private string $name;

    private string $description = '';

    private bool $protected = false;

    /** @var Collection<int, OperPermission> */
    private Collection $permissions;

    private ?string $userModes = null;

    public function __construct()
    {
        $this->permissions = new ArrayCollection();
    }

    public static function create(string $name, string $description = '', bool $protected = false): self
    {
        $role = new self();
        $role->name = strtoupper($name);
        $role->description = $description;
        $role->protected = $protected;

        return $role;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function isProtected(): bool
    {
        return $this->protected;
    }

    /** @return Collection<int, OperPermission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function hasPermission(string $permissionName): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getName() === $permissionName) {
                return true;
            }
        }

        return false;
    }

    public function addPermission(OperPermission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function removePermission(OperPermission $permission): void
    {
        $this->permissions->removeElement($permission);
    }

    /**
     * @return array<int, string>
     */
    public function getUserModes(): array
    {
        if (null === $this->userModes) {
            return [];
        }

        $decoded = json_decode($this->userModes, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, string> $modes
     */
    public function setUserModes(array $modes): void
    {
        if (empty($modes)) {
            $this->userModes = null;

            return;
        }

        $this->userModes = json_encode(array_values(array_unique($modes)));
    }
}
