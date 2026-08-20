<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Entity;

final class Address extends Entity
{
    public string $street = '';

    public string $city = '';
}
