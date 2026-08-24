<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Entity;

final class LineItem extends Entity
{
    public string $sku = '';

    public int $quantity = 0;

    public ?Money $price = null;
}
