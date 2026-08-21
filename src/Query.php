<?php

declare(strict_types=1);

namespace PhpFirebase;

use BackedEnum;
use PhpFirebase\Database\Connection;
use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;

/**
 * An immutable query over a collection of entities.
 *
 * Every method returns a new instance, so a partly built query is safe to keep
 * around and branch from:
 *
 *     $admins = $users->query()->whereEquals('role', 'admin');
 *
 *     $firstTen = $admins->limit(10)->fetch();
 *     $newest   = $admins->orderBy('joinedAt', Direction::Descending)->limit(5)->fetch();
 *
 * What a backend can actually run differs: the Realtime Database filters and
 * orders on one field only and throws {@see \PhpFirebase\Exception\UnsupportedQuery}
 * otherwise, while Firestore wants a composite index for compound queries.
 *
 * A subclass may add methods of its own; override {@see with()} so the chain
 * keeps returning that subclass. The constructor signature is part of the
 * contract, because building the next query in a chain relies on it.
 *
 * @template T of EntityInterface
 *
 * @phpstan-consistent-constructor
 */
readonly class Query
{
    protected readonly Criteria $criteria;

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        protected readonly Connection $connection,
        protected readonly string $collection,
        protected readonly string $entityClass,
        ?Criteria $criteria = null,
    ) {
        $this->criteria = $criteria ?? Criteria::none();
    }

    /**
     * A backed enum may be passed directly; it is compared as its value.
     *
     * @param list<BackedEnum|bool|string|int|float>|BackedEnum|bool|string|int|float $value
     *
     * @return static
     */
    public function where(string $field, Operator $operator, array|BackedEnum|bool|string|int|float $value): static
    {
        return $this->with($this->criteria->where($field, $operator, $value));
    }

    /**
     * @return static
     */
    public function whereEquals(string $field, BackedEnum|bool|string|int|float $value): static
    {
        return $this->where($field, Operator::Equal, $value);
    }

    /**
     * @return static
     */
    public function orderBy(string $field, Direction $direction = Direction::Ascending): static
    {
        return $this->with($this->criteria->orderBy($field, $direction));
    }

    /**
     * @return static
     */
    public function orderByKey(Direction $direction = Direction::Ascending): static
    {
        return $this->with($this->criteria->orderByKey($direction));
    }

    /**
     * @return static
     */
    public function limit(int $limit): static
    {
        return $this->with($this->criteria->limit($limit));
    }

    /**
     * Runs the query and returns the entities keyed by their id.
     *
     * @return array<string, T>
     */
    public function fetch(): array
    {
        $entities = [];

        foreach ($this->connection->readAll($this->collection, $this->criteria) as $id => $record) {
            $entities[$id] = ($this->entityClass)::fromArray($record, $id);
        }

        return $entities;
    }

    /**
     * The first matching entity, or null when nothing matched.
     *
     * @return T|null
     */
    public function first(): ?EntityInterface
    {
        $entities = $this->limit(1)->fetch();

        return array_shift($entities);
    }

    public function count(): int
    {
        return $this->connection->count($this->collection, $this->criteria);
    }

    /**
     * The criteria built so far, for inspection or for handing to a connection
     * directly.
     */
    public function criteria(): Criteria
    {
        return $this->criteria;
    }

    /**
     * Builds the next query in a chain. A subclass adding methods of its own
     * overrides this so the chain keeps returning its type.
     *
     * @return static
     */
    protected function with(Criteria $criteria): static
    {
        return new static($this->connection, $this->collection, $this->entityClass, $criteria);
    }

    /**
     * @return Connection the connection this query runs against
     */
    protected function connection(): Connection
    {
        return $this->connection;
    }

    protected function collection(): string
    {
        return $this->collection;
    }
}
