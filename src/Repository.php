<?php

declare(strict_types=1);

namespace PhpFirebase;

use PhpFirebase\Database\Connection;
use PhpFirebase\Database\Transaction;
use PhpFirebase\Exception\EntityNotFound;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Exception\TransactionConflict;

/**
 * Reads and writes one collection of entities.
 *
 *     final class UserRepository extends Repository
 *     {
 *         protected function collection(): string
 *         {
 *             return 'users';
 *         }
 *
 *         protected function entityClass(): string
 *         {
 *             return User::class;
 *         }
 *     }
 *
 *     $users = new UserRepository($connection);
 *
 * The repository maps entities to arrays and back; where those arrays are
 * stored is the {@see Connection}'s business, so the same repository works
 * against any supported backend.
 *
 * @template T of EntityInterface
 *
 * @implements RepositoryInterface<T>
 */
abstract class Repository implements RepositoryInterface
{
    public function __construct(protected readonly Connection $connection)
    {
    }

    /**
     * The collection these entities live in: a Firestore collection, or a
     * Realtime Database path holding a map of records.
     */
    abstract protected function collection(): string;

    /**
     * @return class-string<T>
     */
    abstract protected function entityClass(): string;

    /**
     * @return T|null
     */
    public function find(string $id): ?EntityInterface
    {
        $record = $this->connection->read($this->collection(), $this->guardId($id));

        return $record === null ? null : ($this->entityClass())::fromArray($record, $id);
    }

    /**
     * @return T
     */
    public function get(string $id): EntityInterface
    {
        return $this->find($id)
            ?? throw EntityNotFound::withId($this->entityClass(), $this->collection(), $id);
    }

    /**
     * @return array<string, T>
     */
    public function all(): array
    {
        return $this->query()->fetch();
    }

    /**
     * @param T $entity
     *
     * @return T
     */
    public function save(EntityInterface $entity): EntityInterface
    {
        $this->guardEntityClass($entity);

        $data = $entity->toDatabase();
        $id = $entity->id();

        if ($id !== null) {
            $this->connection->write($this->collection(), $this->guardId($id), $data);

            return $entity;
        }

        return $entity->withId($this->connection->create($this->collection(), $data));
    }

    /**
     * Stores several entities, in as few round trips as the backend allows.
     *
     * Entities that already have an id are written together in one batch.
     * Entities without one are created individually, because only the backend
     * can allocate their ids.
     *
     * @param iterable<T> $entities
     *
     * @return list<T>
     */
    public function saveMany(iterable $entities): array
    {
        $saved = [];
        $batch = [];

        foreach ($entities as $entity) {
            $this->guardEntityClass($entity);

            $id = $entity->id();

            if ($id === null) {
                $saved[] = $entity->withId($this->connection->create($this->collection(), $entity->toDatabase()));

                continue;
            }

            $batch[$this->guardId($id)] = $entity->toDatabase();
            $saved[] = $entity;
        }

        if ($batch !== []) {
            $this->connection->writeMany($this->collection(), $batch);
        }

        return $saved;
    }

    /**
     * Writes only the given fields, leaving the rest of the record untouched.
     *
     * @param array<string, mixed> $values
     */
    public function update(EntityInterface|string $entity, array $values): void
    {
        $this->connection->merge($this->collection(), $this->idOf($entity), $values);
    }

    public function delete(EntityInterface|string $entity): void
    {
        $this->connection->delete($this->collection(), $this->idOf($entity));
    }

    public function deleteAll(): void
    {
        $this->connection->truncate($this->collection());
    }

    /**
     * Override this to hand out a Query subclass carrying methods of your own;
     * the narrower return type is allowed and is what callers will see.
     *
     * @return Query<T>
     */
    public function query(): Query
    {
        return new Query($this->connection, $this->collection(), $this->entityClass());
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Runs $work against a consistent snapshot of the collection.
     *
     * The callable may run more than once, because a conflict with another
     * writer discards the work and repeats it against fresh data. Keep it free
     * of side effects that cannot be repeated - send the email after it
     * returns, not inside it.
     *
     *     $orders->transaction(function (EntityTransaction $orders) use ($id): void {
     *         $order = $orders->get($id);
     *         $order->status = OrderStatus::Shipped;
     *         $orders->save($order);
     *     });
     *
     * @template TReturn
     *
     * @param callable(EntityTransaction<T>): TReturn $work
     * @param int<1, max> $attempts
     *
     * @throws TransactionConflict when every attempt lost to another writer
     *
     * @return TReturn
     */
    public function transaction(callable $work, int $attempts = 5): mixed
    {
        return $this->connection->transaction(
            fn (Transaction $transaction): mixed => $work(
                new EntityTransaction($transaction, $this->collection(), $this->entityClass()),
            ),
            $attempts,
        );
    }

    /**
     * Reads one entity, hands it to $change, and writes back whatever comes
     * out - safely against other writers.
     *
     * This is the read-modify-write that transactions exist for, and the shape
     * most uses of them take:
     *
     *     $accounts->modify('ada', function (Account $account): Account {
     *         $account->balance -= 10;
     *
     *         return $account;
     *     });
     *
     * @param callable(T): T $change
     *
     * @throws EntityNotFound if there is nothing stored under $id
     * @throws TransactionConflict when every attempt lost to another writer
     *
     * @return T the entity as it was written
     */
    public function modify(string $id, callable $change, int $attempts = 5): EntityInterface
    {
        return $this->transaction(
            function (EntityTransaction $entities) use ($id, $change): EntityInterface {
                $changed = $change($entities->get($id));

                $entities->save($changed);

                return $changed;
            },
            $attempts,
        );
    }

    private function idOf(EntityInterface|string $entity): string
    {
        if (is_string($entity)) {
            return $this->guardId($entity);
        }

        $this->guardEntityClass($entity);

        return $this->guardId($entity->id() ?? throw InvalidEntity::missingId($entity::class));
    }

    private function guardEntityClass(EntityInterface $entity): void
    {
        $expected = $this->entityClass();

        if (!$entity instanceof $expected) {
            throw InvalidEntity::unexpectedClass($expected, $entity::class);
        }
    }

    /**
     * Rejects the ids no backend accepts. Each connection additionally applies
     * its own rules, which differ: a dot is fine in a Firestore document id and
     * illegal in a Realtime Database key.
     */
    private function guardId(string $id): string
    {
        if ($id === '' || str_contains($id, '/')) {
            throw new InvalidEntity(sprintf(
                'The id "%s" is not usable: it must not be empty or contain a slash.',
                $id,
            ));
        }

        return $id;
    }
}
