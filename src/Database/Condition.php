<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

/**
 * One filter in a {@see Criteria}.
 */
final readonly class Condition
{
    /**
     * @param list<bool|string|int|float>|bool|string|int|float $value
     */
    public function __construct(
        public string $field,
        public Operator $operator,
        public array|bool|string|int|float $value,
    ) {
    }
}
