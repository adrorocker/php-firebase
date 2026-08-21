<?php

declare(strict_types=1);

namespace PhpFirebase;

use PhpFirebase\Database\Transaction;
use PhpFirebase\Exception\EntityNotFound;
use PhpFirebase\Exception\InvalidEntity;

/**
 * The typed view of a {@see Transaction}, scoped to one repository's collection.
 *
 * Read through this rather than through the repository: only what is read here
 * is watched for concurrent changes.
 *
 * @template T of EntityInterface
 */
final readonly class EntityTransaction
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private Transaction $transaction,
        private string $collection,
        private string $entityClass,
    ) {
    }

    /**
     * @return T|null
     */
    public function find(string $id): ?EntityInterface
    {
        $record = $this->transaction->read($this->collection, $id);

        return $record === null ? null : ($this->entityClass)::fromArray($record, $id);
    }

    /**
     * @throws EntityNotFound
     *
     * @return T
     */
    public function get(string $id): EntityInterface
    {
        return $this->find($id)
            ?? throw EntityNotFound::withId($this->entityClass, $this->collection, $id);
    }

    /**
     * @param T $entity
     */
    public function save(EntityInterface $entity): void
    {
        if (!$entity instanceof $this->entityClass) {
            throw InvalidEntity::unexpectedClass($this->entityClass, $entity::class);
        }

        $id = $entity->id() ?? throw InvalidEntity::missingId($entity::class);

        $this->transaction->write($this->collection, $id, $entity->toDatabase());
    }

    /**
     * @param T|string $entity
     */
    public function delete(EntityInterface|string $entity): void
    {
        $id = is_string($entity)
            ? $entity
            : $entity->id() ?? throw InvalidEntity::missingId($entity::class);

        $this->transaction->delete($this->collection, $id);
    }
}
