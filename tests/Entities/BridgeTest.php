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
use PhpFirebase\Entities\Bridge;
use PHPUnit\Framework\TestCase;

class BridgeTest extends TestCase
{
    public function testConstruct()
    {
        $u = new User(['id' => 1]);

        $b = new Bridge($u);

        $this->assertInstanceOf(Bridge::class, $b);
    }

    public function testGet()
    {
        $u = new User(['id' => 1, 'name' => 'adro']);

        $b = new Bridge($u);

        $this->assertSame(1, $b->id);
        $this->assertSame('adro', $b->name);
        $this->assertSame(null, $b->test);
    }
}
