<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use PhpFirebase\Repository;

/**
 * @extends Repository<User>
 */
final class UserRepository extends Repository
{
    protected function collection(): string
    {
        return 'users';
    }

    protected function entityClass(): string
    {
        return User::class;
    }
}
