<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */
namespace PhpFirebase\Entities;

use PhpFirebase\Entities\User;
use PHPUnit\Framework\TestCase;

class FunctionTest extends TestCase
{
    public function testGuid()
    {
        $guid = guid();

        $true = false;

        if (1 === preg_match('/^\{?[A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{12}\}?$/', $guid)) {
            $true = true;
        }

        $this->assertTrue($true);
    }
}
