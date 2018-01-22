<?php
/**
 * PHP-Firebase.
 *
 * @link      https://github.com/adrorocker/php-firebase
 *
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
 */

namespace PhpFirebase\Entities;

use PHPUnit\Framework\TestCase;

class CallTest extends TestCase
{
    public function testCall()
    {
        $u = new User(['id' => 1]);

        $u->id(2);

        $this->assertSame(['id' => 2, 'name' => null], $u->toArray());

        $this->assertSame(2, $u->id());
    }

    public function testCalllException()
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The metod "test" does not exist');

        $u = new User(['id' => 1]);

        $u->test();
    }
}
