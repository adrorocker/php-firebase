<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

/**
 * The handle given to the callable running inside {@see Connection::transaction()}.
 *
 * Read what you need through this handle rather than through the connection:
 * only reads made here are watched for concurrent changes, and a write to a
 * record that was not read first is not protected against anything.
 */
interface Transaction
{
    /**
     * @return array<string, mixed>|null
     */
    public function read(string $collection, string $id): ?array;

    /**
     * @param array<string, mixed> $data
     */
    public function write(string $collection, string $id, array $data): void;

    public function delete(string $collection, string $id): void;
}
