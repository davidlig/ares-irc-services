<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Domain\Entity\OperIrcop;

use function array_key_exists;
use function array_key_last;
use function array_splice;

/** Holds prefetched IRCOP assignments only while a NickServ burst batch is active. */
final class BurstIrcopCache
{
    /** @var list<array{token: object, assignments: array<int, OperIrcop|null>}> */
    private array $snapshots = [];

    public function beginBatch(object $token): void
    {
        $this->snapshots[] = ['token' => $token, 'assignments' => []];
    }

    /**
     * @param list<OperIrcop> $assignments
     * @param list<int>       $requestedNickIds
     */
    public function primeBatch(object $token, array $assignments, array $requestedNickIds): void
    {
        /** @var array<int, OperIrcop|null> $snapshot */
        $snapshot = [];
        foreach ($requestedNickIds as $nickId) {
            $snapshot[$nickId] = null;
        }
        foreach ($assignments as $assignment) {
            $snapshot[$assignment->getNickId()] = $assignment;
        }

        foreach ($this->snapshots as $index => $scope) {
            if ($scope['token'] === $token) {
                $this->snapshots[$index]['assignments'] = $snapshot;

                return;
            }
        }
    }

    /** @return array{bool, OperIrcop|null} */
    public function lookup(int $nickId): array
    {
        if ([] === $this->snapshots) {
            return [false, null];
        }

        $snapshot = $this->snapshots[array_key_last($this->snapshots)]['assignments'];

        return array_key_exists($nickId, $snapshot)
            ? [true, $snapshot[$nickId]]
            : [false, null];
    }

    public function finishBatch(object $token): void
    {
        foreach ($this->snapshots as $index => $scope) {
            if ($scope['token'] === $token) {
                array_splice($this->snapshots, $index, 1);

                return;
            }
        }
    }

    public function invalidate(): void
    {
        foreach ($this->snapshots as $index => $_scope) {
            $this->snapshots[$index]['assignments'] = [];
        }
    }
}
