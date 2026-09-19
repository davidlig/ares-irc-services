<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

use function array_keys;
use function glob;
use function simplexml_load_file;
use function sort;
use function sprintf;
use function str_ends_with;

#[CoversNothing]
final class MappingReferencePolicyTest extends TestCase
{
    private const string DOCTRINE_MAPPING_NAMESPACE = 'http://doctrine-project.org/schemas/orm/doctrine-mapping';

    /**
     * Lifecycle policy for every stored nick/channel reference, keyed by table.column
     * (see .agents/persistence.md §8). A column can carry a different policy per table,
     * so keys must be table-qualified.
     */
    private const array REFERENCE_COLUMN_POLICIES = [
        'channel_access.channel_id' => 'cascade',
        'channel_access.nick_id' => 'cascade',
        'channel_akick.channel_id' => 'cascade',
        'channel_akick.creator_nick_id' => 'set_null',
        'channel_history.channel_id' => 'cascade',
        'channel_history.performed_by_nick_id' => 'snapshot',
        'channel_levels.channel_id' => 'cascade',
        'forbidden_vhosts.created_by_nick_id' => 'set_null',
        'gline.creator_nick_id' => 'set_null',
        'memo_ignores.ignored_nick_id' => 'cascade',
        'memo_ignores.target_channel_id' => 'cascade',
        'memo_ignores.target_nick_id' => 'cascade',
        'memo_settings.target_channel_id' => 'cascade',
        'memo_settings.target_nick_id' => 'cascade',
        'memos.sender_nick_id' => 'cascade',
        'memos.target_channel_id' => 'cascade',
        'memos.target_nick_id' => 'cascade',
        'motd.creator_nick_id' => 'cascade',
        'nick_history.nick_id' => 'cascade',
        'nick_history.performed_by_nick_id' => 'snapshot',
        'oper_ircops.added_by_id' => 'set_null',
        'oper_ircops.nick_id' => 'cascade',
        'registered_channels.founder_nick_id' => 'transfer',
        'registered_channels.successor_nick_id' => 'set_null',
    ];

    private const array VALID_POLICIES = ['cascade', 'set_null', 'transfer', 'snapshot'];

    #[Test]
    public function everyMappedNickOrChannelReferenceDeclaresItsLifecyclePolicy(): void
    {
        $mapped = $this->mappedReferenceColumns();
        $declared = array_keys(self::REFERENCE_COLUMN_POLICIES);
        sort($mapped);
        sort($declared);

        self::assertSame(
            $declared,
            $mapped,
            'Every stored nick/channel reference must declare its lifecycle policy in MappingReferencePolicyTest and in .agents/persistence.md §8.',
        );
    }

    #[Test]
    public function declaredPoliciesUseKnownVerbs(): void
    {
        foreach (self::REFERENCE_COLUMN_POLICIES as $column => $policy) {
            self::assertContains(
                $policy,
                self::VALID_POLICIES,
                sprintf('Reference "%s" declares an unknown lifecycle policy.', $column),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function mappedReferenceColumns(): array
    {
        $files = glob(__DIR__ . '/../../config/doctrine/*/*.orm.xml');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $columns = [];
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            self::assertInstanceOf(SimpleXMLElement::class, $xml);
            $xml->registerXPathNamespace('d', self::DOCTRINE_MAPPING_NAMESPACE);

            $entities = $xml->xpath('//d:entity');
            self::assertNotFalse($entities);
            self::assertNotEmpty($entities);
            $table = (string) $entities[0]['table'];

            foreach ((array) $xml->xpath('//d:field[@column]') as $field) {
                self::assertInstanceOf(SimpleXMLElement::class, $field);
                $column = (string) $field['column'];
                if ($this->isReferenceColumn($column)) {
                    $columns[$table . '.' . $column] = true;
                }
            }
        }

        return array_keys($columns);
    }

    private function isReferenceColumn(string $column): bool
    {
        return str_ends_with($column, 'nick_id')
            || str_ends_with($column, 'channel_id')
            || 'added_by_id' === $column;
    }
}
