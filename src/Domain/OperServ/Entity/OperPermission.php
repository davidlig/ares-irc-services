<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

class OperPermission
{
    public const string KILL = 'operserv.kill';

    public const string GLINE = 'operserv.gline';

    private int $id;

    private string $name;

    private string $description = '';

    private function __construct()
    {
    }

    public static function create(string $name, string $description = ''): self
    {
        $permission = new self();
        $permission->name = $name;
        $permission->description = $description;

        return $permission;
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
}
