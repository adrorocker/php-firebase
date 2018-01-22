<?php

namespace PhpFirebase\Entities\Repository;

use PhpFirebase\Entities\User;

class NoClinetUserRepository extends Repository
{
    public function __construct($firebase)
    {
        $this->class = User::class;

        parent::__construct($firebase, '/users');
    }
}
