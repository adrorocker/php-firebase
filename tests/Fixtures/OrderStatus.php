<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';
}
