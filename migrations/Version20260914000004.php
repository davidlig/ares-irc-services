<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create registered_nicks table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('registered_nicks');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('nickname', Types::STRING, ['length' => 32]);
        $table->addColumn('nickname_lower', Types::STRING, ['length' => 32]);
        $table->addColumn('status', Types::STRING, ['length' => 20]);
        $table->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('language', Types::STRING, ['length' => 10]);
        $table->addColumn('registered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('reason', Types::STRING, ['length' => 512, 'notnull' => false]);
        $table->addColumn('suspended_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_seen_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_quit_message', Types::STRING, ['length' => 512, 'notnull' => false]);
        $table->addColumn('last_connect_ip', Types::STRING, ['length' => 45, 'notnull' => false]);
        $table->addColumn('last_connect_host', Types::STRING, ['length' => 256, 'notnull' => false]);
        $table->addColumn('private', Types::BOOLEAN);
        $table->addColumn('vhost', Types::STRING, ['length' => 48, 'notnull' => false]);
        $table->addColumn('timezone', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('msg_privmsg', Types::BOOLEAN);
        $table->addColumn('no_expire', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('pending_deletion_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['nickname_lower'], 'UNIQ_2E946F3710949D4C');
        $table->addUniqueIndex(['email'], 'UNIQ_2E946F37E7927C74');
        $table->addUniqueIndex(['vhost'], 'UNIQ_2E946F375ED32E93');
        $table->addIndex(['nickname_lower'], 'idx_nickname_lower');
        $table->addIndex(['status', 'expires_at'], 'idx_status_expires');
        $table->addIndex(['status', 'pending_deletion_at'], 'idx_status_pending_deletion');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('registered_nicks');
    }
}
