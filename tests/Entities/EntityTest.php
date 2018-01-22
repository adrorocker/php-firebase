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

class EntityTest extends TestCase
{
    public function testEntityConstructor()
    {
        $e = new User(['id' => 1]);

        $this->assertInstanceOf(Entity::class, $e);
    }

    public function testEntityToArray()
    {
        $e = new User(['id' => 1]);

        $this->assertSame(['id' => 1, 'name' => null], $e->toArray());
    }

    public function testEntityToJson()
    {
        $e = new User(['id' => 1]);

        $this->assertSame('{"id":1,"name":null}', $e->toJson());
    }

    public function testEntityFromJson()
    {
        $e = User::fromJson('{"id":1,"name":null}');

        $this->assertSame(['id' => 1, 'name' => null], $e->toArray());
    }
}
