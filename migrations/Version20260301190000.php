<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add mlock_active boolean to registered_channels. MLOCK has two explicit states: on (true) or off (false).
 * Existing rows: mlock_active = 1 where mlock != '', else 0.
 */
final class Version20260301190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mlock_active boolean to registered_channels (MLOCK on/off state).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registered_channels ADD COLUMN mlock_active BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE registered_channels SET mlock_active = 1 WHERE mlock != \'\' AND mlock IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __rc_backup AS SELECT id, name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock, mlock_params, secure, topic, last_topic_set_at, last_used_at, created_at FROM registered_channels');
        $this->addSql('DROP TABLE registered_channels');
        $this->addSql('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, founder_nick_id INTEGER NOT NULL, successor_nick_id INTEGER DEFAULT NULL, description VARCHAR(255) NOT NULL DEFAULT "", url VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, entrymsg VARCHAR(255) NOT NULL DEFAULT "", topic_lock BOOLEAN NOT NULL DEFAULT 0, mlock VARCHAR(64) NOT NULL DEFAULT "", mlock_params CLOB DEFAULT \'[]\' NOT NULL, secure BOOLEAN NOT NULL DEFAULT 0, topic CLOB DEFAULT NULL, last_topic_set_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chanserv_name_lower ON registered_channels (name_lower)');
        $this->addSql('CREATE INDEX idx_chanserv_founder ON registered_channels (founder_nick_id)');
        $this->addSql('INSERT INTO registered_channels (id, name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock, mlock_params, secure, topic, last_topic_set_at, last_used_at, created_at) SELECT id, name, name_lower, founder_nick_id, successor_nick_id, description, url, email, entrymsg, topic_lock, mlock, mlock_params, secure, topic, last_topic_set_at, last_used_at, created_at FROM __rc_backup');
        $this->addSql('DROP TABLE __rc_backup');
    }
}
