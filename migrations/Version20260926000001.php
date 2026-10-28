<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add IRCop-only policy to registered channels';
    }

    public function up(Schema $schema): void
    {
        $column = $this->platform->getColumnDeclarationSQL('ircop_only', ['name' => 'ircop_only', 'typeName' => Types::BOOLEAN, 'default' => false, 'notnull' => true]);
        $add = $this->platform instanceof SQLitePlatform ? ' ADD COLUMN ' : ' ADD ';
        $this->addSql('ALTER TABLE registered_channels' . $add . $column);
    }

    public function down(Schema $schema): void
    {
        if ($this->platform instanceof SQLitePlatform) {
            // Preserve the historical SQLite rebuild, including its index order.
            foreach ([
                'CREATE TEMPORARY TABLE __temp__registered_channels AS SELECT id, name, name_lower, founder_nick_id,'
                . ' successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure,'
                . ' topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, status, suspended_reason,'
                . ' suspended_until, forbidden_reason, no_expire, pending_deletion_at FROM registered_channels',
                'DROP TABLE registered_channels',
                'CREATE TABLE registered_channels (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL,'
                . ' name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL,'
                . ' description VARCHAR(255) NOT NULL, url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg'
                . ' VARCHAR(255) NOT NULL, topic_lock BOOLEAN NOT NULL, mlock_active BOOLEAN NOT NULL, mlock VARCHAR(64) NOT'
                . ' NULL, mlock_params CLOB NOT NULL, secure BOOLEAN NOT NULL, topic CLOB DEFAULT NULL, last_topic_set_at'
                . ' DATETIME DEFAULT NULL, last_topic_set_by_nick VARCHAR(64) DEFAULT NULL, last_used_at DATETIME DEFAULT NULL,'
                . ' created_at DATETIME NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, suspended_reason VARCHAR(255)'
                . ' DEFAULT NULL, suspended_until DATETIME DEFAULT NULL, forbidden_reason VARCHAR(255) DEFAULT NULL, no_expire'
                . ' BOOLEAN DEFAULT 0 NOT NULL, pending_deletion_at DATETIME DEFAULT NULL)',
                'INSERT INTO registered_channels (id, name, name_lower, founder_nick_id, successor_nick_id, description, url,'
                . ' email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure, topic, last_topic_set_at,'
                . ' last_topic_set_by_nick, last_used_at, created_at, status, suspended_reason, suspended_until,'
                . ' forbidden_reason, no_expire, pending_deletion_at) SELECT id, name, name_lower, founder_nick_id,'
                . ' successor_nick_id, description, url, email, entrymsg, topic_lock, mlock_active, mlock, mlock_params, secure,'
                . ' topic, last_topic_set_at, last_topic_set_by_nick, last_used_at, created_at, status, suspended_reason,'
                . ' suspended_until, forbidden_reason, no_expire, pending_deletion_at FROM __temp__registered_channels',
                'DROP TABLE __temp__registered_channels',
                'CREATE INDEX idx_registered_channels_name_id ON registered_channels (name, id)',
                'CREATE UNIQUE INDEX UNIQ_9F13B8A6C0BC2966 ON registered_channels (name_lower)',
                'CREATE INDEX idx_name_lower ON registered_channels (name_lower)',
                'CREATE INDEX idx_registered_channels_successor_nick_id ON registered_channels (successor_nick_id)',
                'CREATE INDEX idx_registered_channels_pending_deletion ON registered_channels (status, pending_deletion_at)',
                'CREATE INDEX idx_registered_channels_status_suspended_until ON registered_channels (status, suspended_until)',
                'CREATE INDEX idx_registered_channels_inactivity ON registered_channels (no_expire, last_used_at, created_at)',
            ] as $sql) {
                $this->addSql($sql);
            }

            return;
        }

        $this->addSql('ALTER TABLE registered_channels DROP ircop_only');
    }
}
