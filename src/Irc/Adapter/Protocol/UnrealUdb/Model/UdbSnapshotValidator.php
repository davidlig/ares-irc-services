<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;

use function count;
use function explode;
use function strtolower;

/** Validates a staged block in path order while retaining at most one aggregate. */
final class UdbSnapshotValidator
{
    /**
     * @param iterable<array{path: string, value: UdbValue, identityPath: string}> $records ordered by binary identityPath
     */
    public function validate(UdbBlock $block, iterable $records): bool
    {
        $groupKey = null;
        $group = [];
        foreach ($records as $record) {
            $components = [];
            foreach (explode('::', $record['path']) as $component) {
                $decoded = UdbPathCodec::decodeComponent($component);
                if (null === $decoded) {
                    return false;
                }
                $components[] = $decoded;
            }
            if (!UdbSchema::validateTyped($block, $components, $record['value'])) {
                return false;
            }

            if (!$this->appendAggregateRow($block, $components, $record['path'], $record['value'], $groupKey, $group)) {
                return false;
            }
        }

        return [] === $group || UdbSchema::validateAggregate($block, $group);
    }

    /**
     * @param list<string>          $components
     * @param array<string, string> $group
     */
    private function appendAggregateRow(UdbBlock $block, array $components, string $path, UdbValue $value, ?string &$groupKey, array &$group): bool
    {
        $nextKey = match ($block) {
            UdbBlock::Nicks => 2 === count($components) ? strtolower($components[0]) : null,
            UdbBlock::Lines => 3 === count($components) && 'f' === strtolower($components[0]) ? 'f::' . $components[1] : null,
            default => null,
        };
        if (null !== $groupKey && $nextKey !== $groupKey) {
            if (!UdbSchema::validateAggregate($block, $group)) {
                return false;
            }
            $group = [];
        }
        $groupKey = $nextKey;
        if (null !== $nextKey && UdbValueKind::None !== $value->kind) {
            $group[$path] = $value->logicalValue();
        }

        return true;
    }
}
