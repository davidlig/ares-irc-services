<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;

class OperRole
{
    private int $id;

    private string $name;

    private string $description = '';

    private bool $protected = false;

    /** @var iterable<int, OperPermission> */
    private iterable $permissions = [];

    private ?string $userModes = null;

    private ?string $forcedVhostPattern = null;

    private ?string $operclass = null;

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

    public function updateDescription(string $description): void
    {
        $this->description = $description;
    }

    public function isProtected(): bool
    {
        return $this->protected;
    }

    /** @return list<OperPermission> */
    public function getPermissions(): array
    {
        return [...$this->permissions];
    }

    public function hasPermission(string $permissionName): bool
    {
        return array_any($this->getPermissions(), static fn ($permission) => $permission->getName() === $permissionName);
    }

    public function addPermission(OperPermission $permission): void
    {
        $permissions = [...$this->permissions];
        if (!in_array($permission, $permissions, true)) {
            $this->permissions = [...$permissions, $permission];
        }
    }

    public function removePermission(OperPermission $permission): void
    {
        $this->permissions = array_values(array_filter(
            [...$this->permissions],
            static fn (OperPermission $current): bool => $current !== $permission,
        ));
    }

    /**
     * @return list<string>
     */
    public function getUserModes(): array
    {
        if (null === $this->userModes) {
            return [];
        }

        $decoded = json_decode($this->userModes, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param list<string> $modes
     */
    public function changeUserModes(array $modes): void
    {
        if (empty($modes)) {
            $this->userModes = null;

            return;
        }

        $encoded = json_encode(array_values(array_unique($modes)));
        $this->userModes = false !== $encoded ? $encoded : null;
    }

    public function getForcedVhostPattern(): ?string
    {
        return $this->forcedVhostPattern;
    }

    public function changeForcedVhostPattern(?string $pattern): void
    {
        $this->forcedVhostPattern = $pattern;
    }

    public function getOperclass(): ?string
    {
        return $this->operclass;
    }

    public function changeOperclass(?string $operclass): void
    {
        $this->operclass = $operclass;
    }
}
