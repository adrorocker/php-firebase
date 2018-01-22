<?php
/**
 * PHP-Firebase.
 *
 * @link      https://github.com/adrorocker/php-firebase
 *
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */

namespace PhpFirebase\Entities\Repository;

interface RepositoryInterface
{
    public function store($entity);

    public function find($id);

    public function fetch(array $searchCriteria);
}
