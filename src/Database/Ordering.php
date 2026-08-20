<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

/**
 * One sort in a {@see Criteria}.
 *
 * The field name may be {@see self::KEY} to sort by the record's own id, which
 * both backends support natively.
 */
final readonly class Ordering
{
    public const string KEY = '__key__';

    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {
    }

    public function isByKey(): bool
    {
        return $this->field === self::KEY;
    }
}
