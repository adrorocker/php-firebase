<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

enum Direction: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
