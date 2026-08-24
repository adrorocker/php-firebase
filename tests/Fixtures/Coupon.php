<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Entity;

/**
 * The other legitimate shape: every persisted property is promoted into the
 * constructor, so the object can never exist half-built. This is the style DDD
 * prefers, and the mapper builds it through the constructor.
 */
final class Coupon extends Entity
{
    public function __construct(
        public readonly string $code = '',
        public readonly int $percentOff = 0,
        public readonly ?Money $minimumSpend = null,
    ) {
    }
}
