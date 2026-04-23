<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: NickServ, ChanServ, MemoServ, OperServ, Messenger tables';
    }

    public function up(Schema $schema): void
    {
        $registeredNicks = $schema->createTable('registered_nicks');
        $registeredNicks->addColumn('id', 'integer', ['autoincrement' => true]);
        $registeredNicks->addColumn('nickname', 'string', ['length' => 32]);
        $registeredNicks->addColumn('nickname_lower', 'string', ['length' => 32]);
        $registeredNicks->addColumn('status', 'string', ['length' => 20]);
        $registeredNicks->addColumn('password_hash', 'string', ['length' => 255, 'notnull' => false]);
        $registeredNicks->addColumn('email', 'string', ['length' => 255, 'notnull' => false]);
        $registeredNicks->addColumn('language', 'string', ['length' => 10]);
        $registeredNicks->addColumn('registered_at', 'datetime_immutable', ['notnull' => false]);
        $registeredNicks->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $registeredNicks->addColumn('reason', 'string', ['length' => 512, 'notnull' => false]);
        $registeredNicks->addColumn('last_seen_at', 'datetime_immutable', ['notnull' => false]);
        $registeredNicks->addColumn('last_quit_message', 'string', ['length' => 512, 'notnull' => false]);
        $registeredNicks->addColumn('private', 'boolean');
        $registeredNicks->addColumn('vhost', 'string', ['length' => 48, 'notnull' => false]);
        $registeredNicks->addColumn('timezone', 'string', ['length' => 64, 'notnull' => false]);
        $registeredNicks->addColumn('msg_privmsg', 'boolean');
        $registeredNicks->setPrimaryKey(['id']);
        $registeredNicks->addUniqueIndex(['nickname_lower'], 'UNIQ_2E946F3710949D4C');
        $registeredNicks->addUniqueIndex(['email'], 'UNIQ_2E946F37E7927C74');
        $registeredNicks->addUniqueIndex(['vhost'], 'UNIQ_2E946F375ED32E93');
        $registeredNicks->addIndex(['nickname_lower'], 'idx_nickname_lower');
        $registeredNicks->addIndex(['status', 'expires_at'], 'idx_status_expires');

        $registeredChannels = $schema->createTable('registered_channels');
        $registeredChannels->addColumn('id', 'integer', ['autoincrement' => true]);
        $registeredChannels->addColumn('name', 'string', ['length' => 64]);
        $registeredChannels->addColumn('name_lower', 'string', ['length' => 64]);
        $registeredChannels->addColumn('founder_nick_id', 'integer');
        $registeredChannels->addColumn('successor_nick_id', 'integer', ['notnull' => false]);
        $registeredChannels->addColumn('description', 'string', ['length' => 255]);
        $registeredChannels->addColumn('url', 'string', ['length' => 255, 'notnull' => false]);
        $registeredChannels->addColumn('email', 'string', ['length' => 255, 'notnull' => false]);
        $registeredChannels->addColumn('entrymsg', 'string', ['length' => 255]);
        $registeredChannels->addColumn('topic_lock', 'boolean');
        $registeredChannels->addColumn('mlock_active', 'boolean');
        $registeredChannels->addColumn('mlock', 'string', ['length' => 64]);
        $registeredChannels->addColumn('mlock_params', 'json');
        $registeredChannels->addColumn('secure', 'boolean');
        $registeredChannels->addColumn('topic', 'text', ['notnull' => false]);
        $registeredChannels->addColumn('last_topic_set_at', 'datetime_immutable', ['notnull' => false]);
        $registeredChannels->addColumn('last_topic_set_by_nick', 'string', ['length' => 64, 'notnull' => false]);
        $registeredChannels->addColumn('last_used_at', 'datetime_immutable', ['notnull' => false]);
        $registeredChannels->addColumn('created_at', 'datetime_immutable');
        $registeredChannels->setPrimaryKey(['id']);
        $registeredChannels->addUniqueIndex(['name_lower'], 'UNIQ_9F13B8A6C0BC2966');
        $registeredChannels->addIndex(['name_lower'], 'idx_name_lower');
        $registeredChannels->addIndex(['successor_nick_id'], 'idx_registered_channels_successor_nick_id');

        $channelAccess = $schema->createTable('channel_access');
        $channelAccess->addColumn('id', 'integer', ['autoincrement' => true]);
        $channelAccess->addColumn('channel_id', 'integer');
        $channelAccess->addColumn('nick_id', 'integer');
        $channelAccess->addColumn('level', 'integer');
        $channelAccess->setPrimaryKey(['id']);
        $channelAccess->addUniqueIndex(['channel_id', 'nick_id'], 'uniq_channel_nick');
        $channelAccess->addIndex(['nick_id'], 'idx_channel_access_nick_id');

        $channelLevels = $schema->createTable('channel_levels');
        $channelLevels->addColumn('id', 'integer', ['autoincrement' => true]);
        $channelLevels->addColumn('channel_id', 'integer');
        $channelLevels->addColumn('level_key', 'string', ['length' => 32]);
        $channelLevels->addColumn('value', 'integer');
        $channelLevels->setPrimaryKey(['id']);
        $channelLevels->addUniqueIndex(['channel_id', 'level_key'], 'uniq_channel_key');

        $memos = $schema->createTable('memos');
        $memos->addColumn('id', 'integer', ['autoincrement' => true]);
        $memos->addColumn('target_nick_id', 'integer', ['notnull' => false]);
        $memos->addColumn('target_channel_id', 'integer', ['notnull' => false]);
        $memos->addColumn('sender_nick_id', 'integer');
        $memos->addColumn('message', 'text');
        $memos->addColumn('created_at', 'datetime_immutable');
        $memos->addColumn('read_at', 'datetime_immutable', ['notnull' => false]);
        $memos->setPrimaryKey(['id']);
        $memos->addIndex(['target_nick_id'], 'idx_memos_target_nick');
        $memos->addIndex(['target_channel_id'], 'idx_memos_target_channel');
        $memos->addIndex(['sender_nick_id'], 'idx_memos_sender');

        $memoSettings = $schema->createTable('memo_settings');
        $memoSettings->addColumn('id', 'integer', ['autoincrement' => true]);
        $memoSettings->addColumn('target_nick_id', 'integer', ['notnull' => false]);
        $memoSettings->addColumn('target_channel_id', 'integer', ['notnull' => false]);
        $memoSettings->addColumn('enabled', 'boolean');
        $memoSettings->setPrimaryKey(['id']);
        $memoSettings->addUniqueIndex(['target_nick_id'], 'uniq_memo_settings_nick');
        $memoSettings->addUniqueIndex(['target_channel_id'], 'uniq_memo_settings_channel');

        $memoIgnores = $schema->createTable('memo_ignores');
        $memoIgnores->addColumn('id', 'integer', ['autoincrement' => true]);
        $memoIgnores->addColumn('target_nick_id', 'integer', ['notnull' => false]);
        $memoIgnores->addColumn('target_channel_id', 'integer', ['notnull' => false]);
        $memoIgnores->addColumn('ignored_nick_id', 'integer');
        $memoIgnores->setPrimaryKey(['id']);
        $memoIgnores->addUniqueIndex(['target_nick_id', 'target_channel_id', 'ignored_nick_id'], 'uniq_memo_ignore_target_ignored');

        $operRoles = $schema->createTable('oper_roles');
        $operRoles->addColumn('id', 'integer', ['autoincrement' => true]);
        $operRoles->addColumn('name', 'string', ['length' => 32]);
        $operRoles->addColumn('description', 'string', ['length' => 255]);
        $operRoles->addColumn('protected', 'boolean');
        $operRoles->setPrimaryKey(['id']);
        $operRoles->addUniqueIndex(['name'], 'uniq_role_name');

        $operPermissions = $schema->createTable('oper_permissions');
        $operPermissions->addColumn('id', 'integer', ['autoincrement' => true]);
        $operPermissions->addColumn('name', 'string', ['length' => 64]);
        $operPermissions->addColumn('description', 'string', ['length' => 255]);
        $operPermissions->setPrimaryKey(['id']);
        $operPermissions->addUniqueIndex(['name'], 'uniq_permission_name');

        $operRolePermissions = $schema->createTable('oper_role_permissions');
        $operRolePermissions->addColumn('role_id', 'integer');
        $operRolePermissions->addColumn('permission_id', 'integer');
        $operRolePermissions->setPrimaryKey(['role_id', 'permission_id']);
        $operRolePermissions->addForeignKeyConstraint('oper_roles', ['role_id'], ['id'], [], 'FK_47D48116D60322AC');
        $operRolePermissions->addForeignKeyConstraint('oper_permissions', ['permission_id'], ['id'], [], 'FK_47D48116FED90CCA');
        $operRolePermissions->addIndex(['role_id'], 'IDX_47D48116D60322AC');
        $operRolePermissions->addIndex(['permission_id'], 'IDX_47D48116FED90CCA');

        $operIrcops = $schema->createTable('oper_ircops');
        $operIrcops->addColumn('id', 'integer', ['autoincrement' => true]);
        $operIrcops->addColumn('nick_id', 'integer');
        $operIrcops->addColumn('role_id', 'integer');
        $operIrcops->addColumn('added_at', 'datetime_immutable');
        $operIrcops->addColumn('added_by_id', 'integer', ['notnull' => false]);
        $operIrcops->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
        $operIrcops->setPrimaryKey(['id']);
        $operIrcops->addUniqueIndex(['nick_id'], 'uniq_ircop_nick');
        $operIrcops->addIndex(['role_id'], 'idx_ircop_role');
        $operIrcops->addForeignKeyConstraint('oper_roles', ['role_id'], ['id'], [], 'FK_533CFD6FD60322AC');

        $messengerMessages = $schema->createTable('messenger_messages');
        $messengerMessages->addColumn('id', 'bigint', ['autoincrement' => true]);
        $messengerMessages->addColumn('body', 'text');
        $messengerMessages->addColumn('headers', 'text');
        $messengerMessages->addColumn('queue_name', 'string', ['length' => 190]);
        $messengerMessages->addColumn('created_at', 'datetime_immutable');
        $messengerMessages->addColumn('available_at', 'datetime_immutable');
        $messengerMessages->addColumn('delivered_at', 'datetime_immutable', ['notnull' => false]);
        $messengerMessages->setPrimaryKey(['id']);
        $messengerMessages->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('messenger_messages');
        $schema->dropTable('oper_ircops');
        $schema->dropTable('oper_role_permissions');
        $schema->dropTable('oper_permissions');
        $schema->dropTable('oper_roles');
        $schema->dropTable('memo_ignores');
        $schema->dropTable('memo_settings');
        $schema->dropTable('memos');
        $schema->dropTable('channel_levels');
        $schema->dropTable('channel_access');
        $schema->dropTable('registered_channels');
        $schema->dropTable('registered_nicks');
    }
}
