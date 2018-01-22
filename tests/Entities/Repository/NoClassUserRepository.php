<?php

namespace PhpFirebase\Entities\Repository;

class NoClassUserRepository extends Repository
{
    public function __construct($firebase)
    {
        parent::__construct($firebase, '/users');
    }
}
