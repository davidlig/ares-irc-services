<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

use function array_keys;
use function count;
use function implode;
use function is_int;
use function is_numeric;
use function is_string;
use function sort;
use function sprintf;
use function strtolower;
use function strtr;

/**
 * Rewrites the nickname and channel lookup keys with the fixed RFC 1459 identity fold.
 *
 * The fold is frozen here on purpose (ASCII lowering plus `[]\^` to `{}|~`): later code changes
 * must not alter what this historical migration writes. Keys are recomputed in PHP from the
 * display columns because database LOWER() applies Unicode casing on PostgreSQL and MySQL.
 */
final class Version20261114000001 extends AbstractMigration
{
    /** @var array<string, array{string, string}> table => [display column, key column] */
    private const array IDENTITY_TABLES = [
        'registered_nicks' => ['nickname', 'nickname_lower'],
        'registered_channels' => ['name', 'name_lower'],
    ];

    public function getDescription(): string
    {
        return 'Fold nickname and channel lookup keys with the fixed RFC 1459 mapping';
    }

    public function preUp(Schema $schema): void
    {
        foreach (self::IDENTITY_TABLES as $table => [$display, $key]) {
            $this->identityUpdates($table, $display, $key, true);
        }
    }

    public function preDown(Schema $schema): void
    {
        foreach (self::IDENTITY_TABLES as $table => [$display, $key]) {
            $this->identityUpdates($table, $display, $key, false);
        }
    }

    public function up(Schema $schema): void
    {
        foreach (self::IDENTITY_TABLES as $table => [$display, $key]) {
            foreach ($this->identityUpdates($table, $display, $key, true) as $id => $value) {
                $this->addSql(
                    sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $key),
                    [$value, $id],
                    [ParameterType::STRING, ParameterType::INTEGER],
                );
            }
        }
        $this->foldServiceNickReservations();
    }

    public function down(Schema $schema): void
    {
        // Rollback statements stay parameter-free so installed rollback simulations replay them verbatim.
        foreach (self::IDENTITY_TABLES as $table => [$display, $key]) {
            foreach ($this->identityUpdates($table, $display, $key, false) as $id => $value) {
                $this->addSql(sprintf('UPDATE %s SET %s = %s WHERE id = %d', $table, $key, $this->connection->quote($value), $id));
            }
        }
        foreach ($this->reservationRows() as [$protocol, $current, $nickname]) {
            $target = strtolower($nickname);
            if ($target !== $current) {
                $this->addSql(sprintf(
                    'UPDATE service_nick_reservations SET nickname_lower = %s WHERE protocol = %s AND nickname_lower = %s',
                    $this->connection->quote($target),
                    $this->connection->quote($protocol),
                    $this->connection->quote($current),
                ));
            }
        }
    }

    private static function fold(string $value): string
    {
        return strtr(strtolower($value), '[]\\^', '{}|~');
    }

    /**
     * Returns the changed keys in an order that never collides with a key still held by another row.
     *
     * @return array<int, string> new key by row id
     */
    private function identityUpdates(string $table, string $display, string $key, bool $fold): array
    {
        // Fresh dry runs have no physical table or records to inspect.
        if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
            return [];
        }

        $currentOwners = [];
        $pending = [];
        $namesByTarget = [];
        foreach ($this->connection->iterateAssociative(sprintf('SELECT id, %s AS display, %s AS lookup FROM %s ORDER BY id', $display, $key, $table)) as $row) {
            $id = $row['id'];
            $name = $row['display'];
            $current = $row['lookup'];
            if ((!is_int($id) && !(is_string($id) && is_numeric($id))) || !is_string($name) || !is_string($current)) {
                throw new AbortMigration(sprintf('Cannot rewrite %s.%s: row with an invalid identity.', $table, $key));
            }
            $id = (int) $id;
            $target = $fold ? self::fold($name) : strtolower($name);
            $namesByTarget[$target][] = $name;
            $currentOwners[$current] = $id;
            if ($target !== $current) {
                $pending[$id] = $target;
            }
        }

        $collisions = [];
        foreach ($namesByTarget as $names) {
            if (1 < count($names)) {
                sort($names);
                $collisions[] = implode(', ', $names);
            }
        }
        if ([] !== $collisions) {
            throw new AbortMigration(sprintf('Cannot fold %s.%s: these names become the same identity and one of each group must be dropped or renamed first: %s', $table, $key, implode('; ', $collisions)));
        }

        $ordered = [];
        $visiting = [];
        foreach (array_keys($pending) as $id) {
            $this->orderUpdate($id, $pending, $currentOwners, $ordered, $visiting, $table, $key);
        }

        return $ordered;
    }

    /**
     * @param array<int, string> $pending
     * @param array<string, int> $currentOwners
     * @param array<int, string> $ordered
     * @param array<int, true>   $visiting
     */
    private function orderUpdate(int $id, array $pending, array $currentOwners, array &$ordered, array &$visiting, string $table, string $key): void
    {
        if (isset($ordered[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            throw new AbortMigration(sprintf('Cannot rewrite %s.%s: stored keys form a cycle; repair them before migrating.', $table, $key));
        }
        $visiting[$id] = true;
        $holder = $currentOwners[$pending[$id]] ?? null;
        // Targets are unique, so a different holder of this target always moves to its own target first.
        if (null !== $holder && $holder !== $id && isset($pending[$holder])) {
            $this->orderUpdate($holder, $pending, $currentOwners, $ordered, $visiting, $table, $key);
        }
        unset($visiting[$id]);
        $ordered[$id] = $pending[$id];
    }

    /**
     * Reservations are rewritten at runtime; refold them in place so stale reservations stay releasable.
     * Spellings that fold to one nick collapse into the row already holding the folded key, if any.
     */
    private function foldServiceNickReservations(): void
    {
        $groups = [];
        foreach ($this->reservationRows() as [$protocol, $current, $nickname]) {
            $groups[$protocol . "\0" . self::fold($nickname)][] = [$protocol, $current, $nickname];
        }

        $updates = [];
        foreach ($groups as $rows) {
            [$protocol, , $nickname] = $rows[0];
            $target = self::fold($nickname);
            $keeper = 0;
            foreach ($rows as $index => [, $current]) {
                if ($current === $target) {
                    $keeper = $index;
                }
            }
            foreach ($rows as $index => [, $current]) {
                if ($index !== $keeper) {
                    $this->addSql(
                        'DELETE FROM service_nick_reservations WHERE protocol = ? AND nickname_lower = ?',
                        [$protocol, $current],
                        [ParameterType::STRING, ParameterType::STRING],
                    );
                } elseif ($current !== $target) {
                    $updates[] = [$target, $protocol, $current];
                }
            }
        }
        foreach ($updates as $parameters) {
            $this->addSql(
                'UPDATE service_nick_reservations SET nickname_lower = ? WHERE protocol = ? AND nickname_lower = ?',
                $parameters,
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
        }
    }

    /** @return list<array{string, string, string}> protocol, stored key and nickname */
    private function reservationRows(): array
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['service_nick_reservations'])) {
            return [];
        }

        $rows = [];
        foreach ($this->connection->iterateAssociative('SELECT protocol, nickname_lower, nickname FROM service_nick_reservations ORDER BY protocol, nickname_lower') as $row) {
            if (!is_string($row['protocol']) || !is_string($row['nickname_lower']) || !is_string($row['nickname'])) {
                throw new AbortMigration('Cannot rewrite service_nick_reservations.nickname_lower: row with an invalid identity.');
            }
            $rows[] = [$row['protocol'], $row['nickname_lower'], $row['nickname']];
        }

        return $rows;
    }
}
