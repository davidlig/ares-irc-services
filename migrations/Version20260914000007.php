<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create registered_channels table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('registered_channels');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 64]);
        $table->addColumn('name_lower', Types::STRING, ['length' => 64]);
        $table->addColumn('founder_nick_id', Types::INTEGER);
        $table->addColumn('successor_nick_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('description', Types::STRING, ['length' => 255]);
        $table->addColumn('url', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('entrymsg', Types::STRING, ['length' => 255]);
        $table->addColumn('topic_lock', Types::BOOLEAN);
        $table->addColumn('mlock_active', Types::BOOLEAN);
        $table->addColumn('mlock', Types::STRING, ['length' => 64]);
        $table->addColumn('mlock_params', Types::JSON);
        $table->addColumn('secure', Types::BOOLEAN);
        $table->addColumn('topic', Types::TEXT, ['notnull' => false]);
        $table->addColumn('last_topic_set_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_topic_set_by_nick', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('status', Types::STRING, ['length' => 20, 'default' => 'active']);
        $table->addColumn('suspended_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('suspended_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('forbidden_reason', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('no_expire', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('pending_deletion_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name_lower'], 'UNIQ_9F13B8A6C0BC2966');
        $table->addIndex(['name_lower'], 'idx_name_lower');
        $table->addIndex(['successor_nick_id'], 'idx_registered_channels_successor_nick_id');
        $table->addIndex(['status', 'pending_deletion_at'], 'idx_registered_channels_pending_deletion');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('registered_channels');
    }
}
