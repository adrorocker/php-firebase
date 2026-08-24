<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Entity;

/**
 * The shape that loses data: a constructor covering only some of the persisted
 * properties. Reading a record would leave $note at its default.
 */
final class HalfBuilt extends Entity
{
    public string $note = '';

    public function __construct(public string $title = '')
    {
    }
}
