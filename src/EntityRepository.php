<?php

declare(strict_types=1);

namespace PhpFirebase;

use PhpFirebase\Database\Connection;
use PhpFirebase\Exception\InvalidEntity;

/**
 * A repository you can use without writing a subclass:
 *
 *     $users = new EntityRepository($connection, 'users', User::class);
 *
 * Subclass {@see Repository} once the collection grows behaviour of its own;
 * this class is here so the simple case stays a one-liner.
 *
 * @template T of EntityInterface
 *
 * @extends Repository<T>
 */
final class EntityRepository extends Repository
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        Connection $connection,
        private readonly string $collection,
        private readonly string $entityClass,
    ) {
        // A runtime guard, because the class name often arrives from config or
        // a container rather than from code static analysis has checked.
        if (!class_exists($entityClass)) {
            throw InvalidEntity::unknownClass($entityClass);
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (!is_a($entityClass, EntityInterface::class, true)) {
            throw InvalidEntity::notAnEntity($entityClass);
        }

        parent::__construct($connection);
    }

    protected function collection(): string
    {
        return $this->collection;
    }

    /**
     * @return class-string<T>
     */
    protected function entityClass(): string
    {
        return $this->entityClass;
    }
}
