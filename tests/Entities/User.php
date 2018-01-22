<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */
namespace PhpFirebase\Entities;

use PhpFirebase\Entities\Entity;

class User extends Entity
{
    protected $id;

    public $name;
}
