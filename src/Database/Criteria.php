<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use BackedEnum;
use InvalidArgumentException;

/**
 * An immutable, backend-neutral description of a query.
 *
 * A {@see Connection} translates this into whatever its backend speaks, and
 * refuses combinations its backend cannot serve.
 *
 * What this describes is deliberately the common ground between backends. For
 * anything beyond it - a Firestore cursor, say - carry it as an extra:
 *
 *     $criteria = $criteria->withExtra('startAfter', ['ada', 42]);
 *
 * A connection that understands the key acts on it; a connection that does not
 * must refuse the query rather than quietly answering a different one. That is
 * what lets someone add a capability in their own connection without forking
 * this class, and without a query silently meaning different things on
 * different backends.
 */
final readonly class Criteria
{
    /**
     * @param list<Condition> $conditions
     * @param list<Ordering> $orderings
     * @param array<string, mixed> $extras
     */
    private function __construct(
        public array $conditions = [],
        public array $orderings = [],
        public ?int $limit = null,
        public array $extras = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Entities store a backed enum as its value, so one may be compared
     * against directly rather than the caller having to unwrap it.
     *
     * @param list<BackedEnum|bool|string|int|float>|BackedEnum|bool|string|int|float $value
     */
    public function where(string $field, Operator $operator, array|BackedEnum|bool|string|int|float $value): self
    {
        if (trim($field) === '') {
            throw new InvalidArgumentException('Cannot filter on an empty field name.');
        }

        return new self(
            [...$this->conditions, new Condition($field, $operator, self::unwrap($value))],
            $this->orderings,
            $this->limit,
            $this->extras,
        );
    }

    public function orderBy(string $field, Direction $direction = Direction::Ascending): self
    {
        if (trim($field) === '') {
            throw new InvalidArgumentException('Cannot order by an empty field name.');
        }

        return new self(
            $this->conditions,
            [...$this->orderings, new Ordering($field, $direction)],
            $this->limit,
            $this->extras,
        );
    }

    public function orderByKey(Direction $direction = Direction::Ascending): self
    {
        return $this->orderBy(Ordering::KEY, $direction);
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('A query limit must be at least 1, got %d.', $limit));
        }

        return new self($this->conditions, $this->orderings, $limit, $this->extras);
    }

    /**
     * Attaches a backend-specific constraint under $key.
     *
     * Only a connection that recognises the key can honour it; every other
     * connection is required to refuse the query.
     */
    public function withExtra(string $key, mixed $value): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('An extra needs a name.');
        }

        return new self($this->conditions, $this->orderings, $this->limit, [...$this->extras, $key => $value]);
    }

    /**
     * @param list<BackedEnum|bool|string|int|float>|BackedEnum|bool|string|int|float $value
     *
     * @return list<bool|string|int|float>|bool|string|int|float
     */
    private static function unwrap(array|BackedEnum|bool|string|int|float $value): array|bool|string|int|float
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return array_map(
                static fn (BackedEnum|bool|string|int|float $item): bool|string|int|float => $item instanceof BackedEnum
                    ? $item->value
                    : $item,
                $value,
            );
        }

        return $value;
    }

    public function extra(string $key): mixed
    {
        return $this->extras[$key] ?? null;
    }

    /**
     * The extras a connection has not claimed to understand.
     *
     * @param list<string> $supported
     *
     * @return list<string>
     */
    public function unsupportedExtras(array $supported): array
    {
        return array_values(array_diff(array_keys($this->extras), $supported));
    }

    /**
     * The distinct fields this query filters on.
     *
     * @return list<string>
     */
    public function filteredFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (Condition $condition): string => $condition->field,
            $this->conditions,
        )));
    }
}
