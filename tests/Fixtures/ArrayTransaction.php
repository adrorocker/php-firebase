<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Database\Transaction;

final readonly class ArrayTransaction implements Transaction
{
    public function __construct(private ArrayConnection $connection)
    {
    }

    public function read(string $collection, string $id): ?array
    {
        return $this->connection->read($collection, $id);
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->connection->write($collection, $id, $data);
    }

    public function delete(string $collection, string $id): void
    {
        $this->connection->delete($collection, $id);
    }
}
