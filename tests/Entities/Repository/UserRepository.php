<?php

namespace PhpFirebase\Entities\Repository;

use PhpFirebase\Entities\User;

class UserRepository extends Repository
{
    public function __construct()
    {
        $base = 'https://example.com/';
        $token = 's0m3t0k3n';

        $this->class = User::class;

        parent::__construct($base, $token, '/users');
    }
}
