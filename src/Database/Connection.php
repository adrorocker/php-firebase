<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use PhpFirebase\Exception\TransactionConflict;
use PhpFirebase\Exception\UnsupportedQuery;

/**
 * A collection-oriented view of a database, and the only thing the entity
 * layer talks to.
 *
 * "Collection" means a Firestore collection, or a path in the Realtime
 * Database holding a map of records; "id" is the document id or the key.
 * Implementations deal in plain arrays, never entities: mapping is
 * {@see \PhpFirebase\Repository}'s job.
 *
 * Every method may throw a backend exception. A connection that cannot serve a
 * given {@see Criteria} must throw {@see UnsupportedQuery} instead of returning
 * results that do not match it.
 */
interface Connection
{
    /**
     * The record stored under $id, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $collection, string $id): ?array;

    /**
     * The matching records, keyed by id, in the order the criteria ask for.
     *
     * @throws UnsupportedQuery
     *
     * @return array<string, array<string, mixed>>
     */
    public function readAll(string $collection, Criteria $criteria): array;

    /**
     * Stores $data under $id, replacing whatever was there.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $collection, string $id, array $data): void;

    /**
     * Stores several records at once, keyed by id, in as few round trips as
     * the backend allows.
     *
     * Like {@see write()}, each record replaces whatever was stored under its
     * id. Whether the batch is atomic is the backend's business.
     *
     * @param array<string, array<string, mixed>> $records
     */
    public function writeMany(string $collection, array $records): void;

    /**
     * Stores $data under a backend-generated id, and returns that id.
     *
     * @param array<string, mixed> $data
     */
    public function create(string $collection, array $data): string;

    /**
     * Writes only the given fields, leaving the rest of the record alone.
     *
     * @param array<string, mixed> $data
     */
    public function merge(string $collection, string $id, array $data): void;

    public function delete(string $collection, string $id): void;

    /**
     * Removes every record in the collection.
     */
    public function truncate(string $collection): void;

    /**
     * The number of matching records.
     *
     * @throws UnsupportedQuery
     */
    public function count(string $collection, Criteria $criteria): int;

    /**
     * Runs $work against a consistent snapshot, and commits it only if nothing
     * it read has changed in the meantime.
     *
     * The callable may run more than once: on a conflict the work is discarded
     * and repeated against fresh data, so it must be safe to repeat and must
     * not do anything outside the transaction that cannot be undone.
     *
     * How much is guaranteed differs by backend, and the difference matters:
     * Firestore commits every write together or none of them, while the
     * Realtime Database applies each write on its own, so a transaction that
     * touches two records there can leave the first written and the second
     * refused.
     *
     * @template TReturn
     *
     * @param callable(Transaction): TReturn $work
     * @param int<1, max> $attempts how many times to try before giving up
     *
     * @throws TransactionConflict when every attempt lost to another writer
     *
     * @return TReturn
     */
    public function transaction(callable $work, int $attempts = 5): mixed;
}
