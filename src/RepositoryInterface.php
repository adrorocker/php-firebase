<?php

declare(strict_types=1);

namespace PhpFirebase;

use PhpFirebase\Exception\EntityNotFound;
use PhpFirebase\Exception\TransactionConflict;

/**
 * Reads and writes one collection of entities, whichever backend stores it.
 *
 * @template T of EntityInterface
 */
interface RepositoryInterface
{
    /**
     * The entity stored under $id, or null when there is none.
     *
     * @return T|null
     */
    public function find(string $id): ?EntityInterface;

    /**
     * Like find(), but insists that the entity exists.
     *
     * @throws EntityNotFound
     *
     * @return T
     */
    public function get(string $id): EntityInterface;

    /**
     * Every entity in the collection, keyed by id.
     *
     * @return array<string, T>
     */
    public function all(): array;

    /**
     * Stores the entity and returns it carrying its id.
     *
     * An entity without an id gets one from the backend. An entity with an id
     * overwrites whatever is stored under it.
     *
     * @param T $entity
     *
     * @return T
     */
    public function save(EntityInterface $entity): EntityInterface;

    /**
     * @param iterable<T> $entities
     *
     * @return list<T>
     */
    public function saveMany(iterable $entities): array;

    /**
     */
    public function delete(EntityInterface|string $entity): void;

    /**
     * Removes every entity under the path.
     *
     */
    public function deleteAll(): void;

    /**
     * Starts a query over the collection.
     *
     * @return Query<T>
     */
    public function query(): Query;

    /**
     * Writes only the given fields of one entity, leaving the rest alone.
     *
     * @param array<string, mixed> $values
     */
    public function update(EntityInterface|string $entity, array $values): void;

    /**
     * The number of entities in the collection.
     */
    public function count(): int;

    /**
     * Runs $work against a consistent snapshot, retrying if another writer got
     * there first. The callable may therefore run more than once.
     *
     * @template TReturn
     *
     * @param callable(EntityTransaction<T>): TReturn $work
     * @param int<1, max> $attempts
     *
     * @throws TransactionConflict
     *
     * @return TReturn
     */
    public function transaction(callable $work, int $attempts = 5): mixed;

    /**
     * Read one entity, change it, write it back, safely against other writers.
     *
     * @param callable(T): T $change
     * @param int<1, max> $attempts
     *
     * @throws EntityNotFound
     * @throws TransactionConflict
     *
     * @return T
     */
    public function modify(string $id, callable $change, int $attempts = 5): EntityInterface;
}
