<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\Query as DatabaseQuery;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\Transaction as DatabaseTransaction;
use Kreait\Firebase\Exception\Database\PreconditionFailed;
use Kreait\Firebase\Exception\Database\TransactionFailed;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Exception\TransactionConflict;
use PhpFirebase\Exception\UnsupportedQuery;

/**
 * The Firebase Realtime Database, through kreait/firebase-php.
 *
 * The Realtime Database is a single JSON tree, so a "collection" here is a path
 * holding a map of records. It can filter and sort on one field only, and it
 * always sorts ascending; this class emulates descending order client side and
 * refuses anything it genuinely cannot express.
 */
class RealtimeDatabaseConnection implements Connection
{
    private const string BACKEND = 'The Realtime Database';

    /**
     * Characters the Realtime Database refuses in a key.
     */
    private const string ILLEGAL_KEY_CHARACTERS = '.$#[]/';

    public function __construct(protected readonly Database $database)
    {
    }

    public function read(string $collection, string $id): ?array
    {
        $value = $this->child($collection, $id)->getValue();

        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw InvalidEntity::cannotHydrate($collection, $id, sprintf('expected a record, got %s.', get_debug_type($value)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function readAll(string $collection, Criteria $criteria): array
    {
        $plan = $this->plan($criteria);
        $value = $this->apply($this->reference($collection), $plan)->getValue();

        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw InvalidEntity::notAnObjectMap($collection, $collection);
        }

        $records = [];

        foreach ($value as $key => $record) {
            $key = (string) $key;

            if (!is_array($record)) {
                throw InvalidEntity::cannotHydrate($collection, $key, sprintf('expected a record, got %s.', get_debug_type($record)));
            }

            /** @var array<string, mixed> $record */
            $records[$key] = $record;
        }

        // The database always answers in ascending order, so a descending
        // query is served by reading the matching window and flipping it.
        return $plan->descending ? array_reverse($records, preserve_keys: true) : $records;
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->set($this->child($collection, $id), RealtimeWrite::resolve($data));
    }

    public function writeMany(string $collection, array $records): void
    {
        if ($records === []) {
            return;
        }

        foreach (array_keys($records) as $id) {
            $this->guardKey($id);
        }

        // A multi-path update writes every record in one request. Each key is
        // a path relative to the collection, so this replaces those children
        // and leaves the rest of the collection alone.
        $this->reference($collection)->update(RealtimeWrite::resolveAll($records));
    }

    public function create(string $collection, array $data): string
    {
        $key = $this->reference($collection)->push(RealtimeWrite::resolve($data)->values)->getKey();

        if ($key === null) {
            // Unreachable in practice: a pushed reference always has a key.
            throw new UnsupportedQuery('The database did not return a key for the pushed record.');
        }

        return $key;
    }

    public function merge(string $collection, string $id, array $data): void
    {
        $this->child($collection, $id)->update(RealtimeWrite::resolve($data)->values);
    }

    /**
     * The one place a value is handed to the database for a single location.
     *
     * It takes a {@see RealtimeWrite} rather than an array, which is what makes
     * skipping sentinel resolution impossible: the only way to have one is to
     * have resolved.
     *
     * @internal for {@see RealtimeTransaction}
     */
    public function set(Reference $reference, RealtimeWrite $write): void
    {
        $reference->set($write->values);
    }


    public function delete(string $collection, string $id): void
    {
        $this->child($collection, $id)->remove();
    }

    public function truncate(string $collection): void
    {
        $this->reference($collection)->remove();
    }

    public function count(string $collection, Criteria $criteria): int
    {
        // The Realtime Database has no count operation, so this reads the
        // matching records. Keep a counter of your own for large collections.
        return count($this->readAll($collection, $criteria));
    }

    public function transaction(callable $work, int $attempts = 5): mixed
    {
        $failure = null;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                return $this->database->runTransaction(
                    fn (DatabaseTransaction $transaction): mixed => $work(new RealtimeTransaction($transaction, $this)),
                );
            } catch (TransactionFailed $e) {
                // kreait reports every failed write inside a transaction this
                // way, including ones that have nothing to do with contention.
                // Only a refused precondition means someone else got there
                // first; anything else will fail again just as surely on the
                // next attempt, so it is raised as itself.
                if (!$e->getPrevious() instanceof PreconditionFailed) {
                    throw $e;
                }

                $failure = $e;
            }
        }

        throw new TransactionConflict($attempts, $failure);
    }

    /**
     * The underlying reference, for anything this class does not cover.
     */
    public function reference(string $collection): Reference
    {
        return $this->database->getReference($collection);
    }

    /**
     * @internal for {@see RealtimeTransaction}
     */
    public function child(string $collection, string $id): Reference
    {
        return $this->reference($collection)->getChild($this->guardKey($id));
    }

    private function guardKey(string $id): string
    {
        if ($id === '' || strpbrk($id, self::ILLEGAL_KEY_CHARACTERS) !== false) {
            throw new InvalidEntity(sprintf(
                'The id "%s" is not a usable Realtime Database key: it must not be empty or contain any of %s',
                $id,
                self::ILLEGAL_KEY_CHARACTERS,
            ));
        }

        return $id;
    }

    /**
     * Works out the single ordering and set of filters the query needs, and
     * rejects it if the Realtime Database cannot express it.
     */
    private function plan(Criteria $criteria): RealtimeQueryPlan
    {
        $unsupported = $criteria->unsupportedExtras($this->supportedExtras());

        if ($unsupported !== []) {
            throw UnsupportedQuery::unknownExtras(self::BACKEND, $unsupported);
        }

        if (count($criteria->orderings) > 1) {
            throw UnsupportedQuery::because(self::BACKEND, 'it can order by one field only.');
        }

        $fields = $criteria->filteredFields();

        if (count($fields) > 1) {
            throw UnsupportedQuery::because(self::BACKEND, sprintf(
                'it can filter on one field only, and this query filters on %s.',
                implode(', ', $fields),
            ));
        }

        foreach ($criteria->conditions as $condition) {
            if ($condition->operator === Operator::In || $condition->operator === Operator::Contains) {
                throw UnsupportedQuery::because(self::BACKEND, sprintf(
                    'it has no "%s" operator.',
                    $condition->operator->value,
                ));
            }
        }

        $ordering = $criteria->orderings[0] ?? null;
        $filterField = $fields[0] ?? null;

        if ($ordering !== null && $filterField !== null) {
            $orderingField = $ordering->isByKey() ? Ordering::KEY : $ordering->field;

            if ($orderingField !== $filterField) {
                throw UnsupportedQuery::because(self::BACKEND, sprintf(
                    'it must order by the field it filters on, but this query orders by "%s" and filters on "%s".',
                    $orderingField,
                    $filterField,
                ));
            }
        }

        // Filtering implies an ordering on the same field, so one is derived
        // when the caller only asked to filter.
        $ordering ??= $filterField !== null ? new Ordering($filterField) : null;

        // The database refuses a limit with nothing to order by: "orderBy must
        // be defined when other query parameters are defined". Taking the first
        // n of a keyed collection means in key order, so that is supplied
        // rather than handing the caller back an error they can only fix one
        // way.
        if ($ordering === null && $criteria->limit !== null) {
            $ordering = new Ordering(Ordering::KEY);
        }

        return new RealtimeQueryPlan(
            ordering: $ordering,
            conditions: $criteria->conditions,
            limit: $criteria->limit,
            descending: $ordering !== null && $ordering->direction === Direction::Descending,
        );
    }

    /**
     * The extra keys this connection knows how to honour. Override in a
     * subclass that adds one.
     *
     * @return list<string>
     */
    protected function supportedExtras(): array
    {
        return [];
    }

    private function apply(Reference $reference, RealtimeQueryPlan $plan): Reference|DatabaseQuery
    {
        $query = $reference;
        $ordering = $plan->ordering;

        if ($ordering !== null) {
            $query = $ordering->isByKey() ? $query->orderByKey() : $query->orderByChild($ordering->field);
        }

        foreach ($plan->conditions as $condition) {
            if (is_array($condition->value)) {
                throw UnsupportedQuery::because(self::BACKEND, 'it cannot compare against a list of values.');
            }

            $query = match ($condition->operator) {
                Operator::Equal => $query->equalTo($condition->value),
                Operator::GreaterThanOrEqual => $query->startAt($condition->value),
                Operator::GreaterThan => $query->startAfter($condition->value),
                Operator::LessThanOrEqual => $query->endAt($condition->value),
                Operator::LessThan => $query->endBefore($condition->value),
                default => throw UnsupportedQuery::because(self::BACKEND, sprintf('it has no "%s" operator.', $condition->operator->value)),
            };
        }

        if ($plan->limit !== null) {
            // Taking the last N of an ascending result is how the database
            // expresses "the first N of the descending one".
            $query = $plan->descending ? $query->limitToLast($plan->limit) : $query->limitToFirst($plan->limit);
        }

        return $query;
    }
}
