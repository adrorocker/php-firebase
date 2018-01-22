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

use PhpFirebase\Entities\Repository\NoClassUserRepository;
use PhpFirebase\Entities\Repository\NoClinetUserRepository;
use PhpFirebase\Entities\Repository\UserRepository;
use PhpFirebase\Firebase;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase
{
    public function testConstruct()
    {
        $firebase = $this->createMock(Firebase::class);
        $r = new UserRepository();

        $this->assertInstanceOf(UserRepository::class, $r);

        $r = new NoClassUserRepository($firebase);

        $this->assertInstanceOf(NoClassUserRepository::class, $r);
    }

    public function testStore()
    {
        $firebase = $this->createMock(Firebase::class);
        $firebase->method('get')
            ->willReturn(['id' => 1, 'name' => null]);

        $repo = new NoClinetUserRepository($firebase);

        $u = new User(['id' => 1]);

        $ur = $repo->store($u);

        $this->assertSame(['id' => 1, 'name' => null], $ur->toArray());

        $ur = $repo->store([$u]);

        $this->assertSame(['id' => 1, 'name' => null], $ur[0]->toArray());
    }

    public function testFetch()
    {
        $firebase = $this->createMock(Firebase::class);
        $firebase->method('get')
            ->willReturn([['id' => 1, 'name' => null]]);

        $repo = new NoClinetUserRepository($firebase);

        $users = $repo->fetch(['id' => 1]);

        $this->assertSame(['id' => 1, 'name' => null], $users[1]->toArray());
    }

    public function testGet()
    {
        $firebase = $this->createMock(Firebase::class);
        $firebase->method('get')
            ->willReturn(['id' => 1, 'name' => null]);

        $repo = new NoClinetUserRepository($firebase);

        $u = new User(['id' => 1]);

        $ur = $repo->store($u);

        $this->assertSame(['id' => 1, 'name' => null], $repo->get()->toArray());
    }

    public function testQueryToTailOrderBy()
    {
        $firebase = $this->createMock(Firebase::class);

        $query = (new NoClinetUserRepository($firebase))->query(['id' => 1]);

        $this->assertInstanceOf(NoClinetUserRepository::class, $query);

        $this->assertInstanceOf(NoClinetUserRepository::class, $query->query(['id' => 1], true));

        $this->assertInstanceOf(NoClinetUserRepository::class, $query->top(1));

        $this->assertInstanceOf(NoClinetUserRepository::class, $query->tail(1));

        $this->assertInstanceOf(NoClinetUserRepository::class, $query->orderBy('id'));
    }

    public function testDeleteAll()
    {
        $firebase = $this->createMock(Firebase::class);

        $repo = new NoClinetUserRepository($firebase);

        $this->assertInstanceOf(NoClinetUserRepository::class, $repo->deleteAll());
    }
}
