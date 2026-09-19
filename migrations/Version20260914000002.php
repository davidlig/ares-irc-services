<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000002 extends AbstractMigration
{
    private const array PROTECTED_ROLES = [
        ['ADMIN', 'Administrator role with full access'],
        ['OPER', 'Operator role with standard access'],
        ['PREOPER', 'Pre-operator role with limited access'],
    ];

    public function getDescription(): string
    {
        return 'Create oper_roles and oper_role_permissions tables and seed the protected roles';
    }

    public function up(Schema $schema): void
    {
        $roles = $schema->createTable('oper_roles');
        $roles->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $roles->addColumn('name', Types::STRING, ['length' => 32]);
        $roles->addColumn('description', Types::STRING, ['length' => 255]);
        $roles->addColumn('protected', Types::BOOLEAN);
        $roles->addColumn('user_modes', Types::TEXT, ['notnull' => false]);
        $roles->addColumn('forced_vhost_pattern', Types::STRING, ['length' => 255, 'notnull' => false]);
        $roles->addColumn('operclass', Types::STRING, ['length' => 255, 'notnull' => false]);
        $roles->setPrimaryKey(['id']);
        $roles->addUniqueIndex(['name'], 'uniq_role_name');

        $rolePermissions = $schema->createTable('oper_role_permissions');
        $rolePermissions->addColumn('role_id', Types::INTEGER);
        $rolePermissions->addColumn('permission_id', Types::INTEGER);
        $rolePermissions->setPrimaryKey(['role_id', 'permission_id']);
        $rolePermissions->addForeignKeyConstraint('oper_roles', ['role_id'], ['id'], [], 'FK_47D48116D60322AC');
        $rolePermissions->addForeignKeyConstraint('oper_permissions', ['permission_id'], ['id'], [], 'FK_47D48116FED90CCA');
        $rolePermissions->addIndex(['role_id'], 'IDX_47D48116D60322AC');
        $rolePermissions->addIndex(['permission_id'], 'IDX_47D48116FED90CCA');
    }

    public function postUp(Schema $schema): void
    {
        foreach (self::PROTECTED_ROLES as [$name, $description]) {
            $this->connection->insert('oper_roles', [
                'name' => $name,
                'description' => $description,
                'protected' => true,
            ], ['protected' => Types::BOOLEAN]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('oper_role_permissions');
        $schema->dropTable('oper_roles');
    }
}
