<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigration;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000002 extends AbstractMigration implements EditedSchemaMigration
{
    private const array PROTECTED_ROLES = [
        ['ADMIN', 'Administrator role with full access'],
        ['OPER', 'Operator role with standard access'],
        ['PREOPER', 'Pre-operator role with limited access'],
    ];

    private ?Schema $editedSchema = null;

    public function getEditedSchema(): ?Schema
    {
        return null === $this->editedSchema ? null : clone $this->editedSchema;
    }

    public function getDescription(): string
    {
        return 'Create oper_roles and oper_role_permissions tables and seed the protected roles';
    }

    public function preUp(Schema $schema): void
    {
        $this->editedSchema = null;
        $schema->getTables();
    }

    public function preDown(Schema $schema): void
    {
        $this->editedSchema = null;
        $schema->getTables();
    }

    public function up(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $roles = Table::editor()->setUnquotedName('oper_roles');
        $roles->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('name')->setTypeName(Types::STRING)->setLength(32)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('description')->setTypeName(Types::STRING)->setLength(255)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('protected')->setTypeName(Types::BOOLEAN)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('user_modes')->setTypeName(Types::TEXT)->setNotNull(false)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('forced_vhost_pattern')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $roles->addColumn(Column::editor()->setUnquotedName('operclass')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create());
        $roles->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['id'])->create());
        $roles->addIndex(Index::editor()->setUnquotedName('uniq_role_name')->setType(IndexType::UNIQUE)->setUnquotedColumnNames(...['name'])->create());
        $editor->addTable($roles->create());
        $rolePermissions = Table::editor()->setUnquotedName('oper_role_permissions');
        $rolePermissions->addColumn(Column::editor()->setUnquotedName('role_id')->setTypeName(Types::INTEGER)->create());
        $rolePermissions->addColumn(Column::editor()->setUnquotedName('permission_id')->setTypeName(Types::INTEGER)->create());
        $rolePermissions->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedName('primary')->setUnquotedColumnNames(...['role_id', 'permission_id'])->create());
        $rolePermissions->addForeignKeyConstraint(ForeignKeyConstraint::editor()->setUnquotedName('FK_47D48116D60322AC')->setUnquotedReferencingColumnNames(...['role_id'])->setUnquotedReferencedTableName('oper_roles')->setUnquotedReferencedColumnNames(...['id'])->create());
        $rolePermissions->addForeignKeyConstraint(ForeignKeyConstraint::editor()->setUnquotedName('FK_47D48116FED90CCA')->setUnquotedReferencingColumnNames(...['permission_id'])->setUnquotedReferencedTableName('oper_permissions')->setUnquotedReferencedColumnNames(...['id'])->create());
        $rolePermissions->addIndex(Index::editor()->setUnquotedName('IDX_47D48116D60322AC')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['role_id'])->create());
        $rolePermissions->addIndex(Index::editor()->setUnquotedName('IDX_47D48116FED90CCA')->setType(IndexType::REGULAR)->setUnquotedColumnNames(...['permission_id'])->create());
        $editor->addTable($rolePermissions->create());

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
        foreach (self::PROTECTED_ROLES as [$name, $description]) {
            $this->addSql('INSERT INTO oper_roles (name, description, protected) VALUES (?, ?, ?)', [$name, $description, true], [Types::STRING, Types::STRING, Types::BOOLEAN]);
        }
    }

    public function down(Schema $schema): void
    {
        $editor = (clone $schema)->edit();
        $editor->dropTableByUnquotedName('oper_role_permissions');
        $editor->dropTableByUnquotedName('oper_roles');

        $target = $editor->create();
        $this->editedSchema = clone $target;
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas(clone $schema, $target);
        foreach ($this->platform->getAlterSchemaSQL($diff) as $sql) {
            $this->addSql($sql);
        }
    }
}
