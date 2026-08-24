<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use PhpFirebase\Database\Transaction;

/**
 * A {@see Transaction} over Firestore.
 *
 * Reads go straight to the server, tagged with the transaction so Firestore
 * knows what this work depends on. Writes are held here until the transaction
 * commits, and then applied together: either all of them land or none do.
 *
 * @internal
 */
final class FirestoreTransaction implements Transaction
{
    /** @var list<array<string, mixed>> */
    private array $writes = [];

    public function __construct(
        private readonly FirestoreConnection $connection,
        private readonly string $id,
    ) {
    }

    public function read(string $collection, string $id): ?array
    {
        return $this->connection->readInTransaction($collection, $id, $this->id);
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->writes[] = $this->connection->updateWrite($collection, $id, $data);
    }

    public function delete(string $collection, string $id): void
    {
        $this->writes[] = $this->connection->deleteWrite($collection, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function writes(): array
    {
        return $this->writes;
    }
}
