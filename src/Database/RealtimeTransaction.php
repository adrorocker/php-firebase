<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use Kreait\Firebase\Database\Transaction as DatabaseTransaction;
use PhpFirebase\Exception\InvalidEntity;

/**
 * A {@see Transaction} over the Realtime Database.
 *
 * The database has no multi-document transaction. What it has is a conditional
 * write: reading a location returns a version tag, and writing it back is
 * refused if that tag has moved on. So each write here is protected
 * individually, and a transaction touching two records can leave the first one
 * written and the second refused.
 *
 * For the usual case - read one record, change it, write it back - that is
 * exactly the guarantee you want.
 *
 * @internal
 */
final readonly class RealtimeTransaction implements Transaction
{
    public function __construct(
        private DatabaseTransaction $transaction,
        private RealtimeDatabaseConnection $connection,
    ) {
    }

    public function read(string $collection, string $id): ?array
    {
        // Reading through the transaction is what records the version tag the
        // later write is checked against.
        $value = $this->transaction->snapshot($this->connection->child($collection, $id))->getValue();

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw InvalidEntity::cannotHydrate($collection, $id, sprintf('expected a record, got %s.', get_debug_type($value)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function write(string $collection, string $id, array $data): void
    {
        // A RealtimeWrite cannot exist without having been resolved, so a
        // sentinel written inside a transaction means what it does everywhere
        // else, and cannot be forgotten here.
        $this->transaction->set(
            $this->connection->child($collection, $id),
            RealtimeWrite::resolve($data)->values,
        );
    }

    public function delete(string $collection, string $id): void
    {
        $this->transaction->remove($this->connection->child($collection, $id));
    }
}
