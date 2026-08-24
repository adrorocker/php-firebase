<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Database\Connection;
use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;

/**
 * An in-memory Connection.
 *
 * Its job in the test suite is to prove that the entity layer really is
 * backend-neutral: the same repositories and queries run against it with no
 * Firebase anywhere in sight.
 */
final class ArrayConnection implements Connection
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $data = [];

    private int $nextId = 1;

    public function read(string $collection, string $id): ?array
    {
        return $this->data[$collection][$id] ?? null;
    }

    public function readAll(string $collection, Criteria $criteria): array
    {
        $records = $this->data[$collection] ?? [];

        foreach ($criteria->conditions as $condition) {
            $records = array_filter(
                $records,
                static fn (array $record): bool => self::matches($record[$condition->field] ?? null, $condition->operator, $condition->value),
            );
        }

        foreach (array_reverse($criteria->orderings) as $ordering) {
            uksort($records, static function (string $a, string $b) use ($ordering, $records): int {
                $left = $ordering->isByKey() ? $a : ($records[$a][$ordering->field] ?? null);
                $right = $ordering->isByKey() ? $b : ($records[$b][$ordering->field] ?? null);

                $result = $left <=> $right;

                return $ordering->direction === Direction::Descending ? -$result : $result;
            });
        }

        if ($criteria->limit !== null) {
            $records = array_slice($records, 0, $criteria->limit, preserve_keys: true);
        }

        return $records;
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->data[$collection][$id] = $data;
    }

    public function writeMany(string $collection, array $records): void
    {
        foreach ($records as $id => $data) {
            $this->write($collection, (string) $id, $data);
        }
    }

    public function create(string $collection, array $data): string
    {
        $id = sprintf('generated-%d', $this->nextId++);
        $this->write($collection, $id, $data);

        return $id;
    }

    public function merge(string $collection, string $id, array $data): void
    {
        $this->data[$collection][$id] = [...$this->data[$collection][$id] ?? [], ...$data];
    }

    public function delete(string $collection, string $id): void
    {
        unset($this->data[$collection][$id]);
    }

    public function truncate(string $collection): void
    {
        unset($this->data[$collection]);
    }

    public function count(string $collection, Criteria $criteria): int
    {
        return count($this->readAll($collection, $criteria));
    }

    public function transaction(callable $work, int $attempts = 5): mixed
    {
        // Nothing else can be writing to an in-memory store, so the work is
        // simply run once against it.
        return $work(new ArrayTransaction($this));
    }

    /**
     * @param list<bool|string|int|float>|bool|string|int|float $expected
     */
    private static function matches(mixed $actual, Operator $operator, array|bool|string|int|float $expected): bool
    {
        if (is_array($expected)) {
            return $operator === Operator::In && in_array($actual, $expected, strict: true);
        }

        return match ($operator) {
            Operator::Equal => $actual === $expected,
            Operator::LessThan => $actual < $expected,
            Operator::LessThanOrEqual => $actual <= $expected,
            Operator::GreaterThan => $actual > $expected,
            Operator::GreaterThanOrEqual => $actual >= $expected,
            Operator::Contains => is_array($actual) && in_array($expected, $actual, strict: true),
            Operator::In => false,
        };
    }
}
