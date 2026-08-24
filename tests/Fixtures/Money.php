<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

/**
 * A plain value object: no base class, no interface, no persistence concerns.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
    }
}
