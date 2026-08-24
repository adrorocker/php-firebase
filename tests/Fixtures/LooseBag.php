<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Entity;

/**
 * An entity with an unconstrained property, where PHP's own type system will
 * not catch a value that cannot be stored.
 */
final class LooseBag extends Entity
{
    public mixed $anything = null;
}
