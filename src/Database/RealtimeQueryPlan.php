<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

/**
 * A {@see Criteria} reduced to what the Realtime Database can actually run.
 *
 * @internal
 */
final readonly class RealtimeQueryPlan
{
    /**
     * @param list<Condition> $conditions
     */
    public function __construct(
        public ?Ordering $ordering,
        public array $conditions,
        public ?int $limit,
        public bool $descending,
    ) {
    }
}
