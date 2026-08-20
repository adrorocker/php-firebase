<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}
