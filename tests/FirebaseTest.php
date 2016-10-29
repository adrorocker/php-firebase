<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase;

use PHPUnit\Framework\TestCase;
use PhpFirebase\Firebase;
use PhpFirebase\Clients\FakeClient;

class FirebaseTest extends TestCase
{
    public function testFirebaseBase()
    {
        $base = 'https://example.com/';
        $token = 's0m3t0k3n';

        $fb = new Firebase($base,$token);

        $this->assertEquals('https://example.com', $fb->getBase());
    }

    public function testFirebaseClient()
    {
        $base = 'https://example.com/';
        $token = 's0m3t0k3n';
        $client = new FakeClient;

        $fb = new Firebase($base,$token,$client);

        $this->assertSame($client, $fb->getClient());

        $me = $fb->get('/');

        $this->assertSame($me,$fb);

        $response = $me->getResponse();

        $this->assertNull($response);

        $response = $me->post('/',['key'=>'value'])->getResponse();

        $this->assertNull($response);

        $response = $me->put('/',['key'=>'value'])->getResponse();

        $this->assertNull($response);

        $response = $me->patch('/',['key'=>'value'])->getResponse();

        $this->assertNull($response);
    }

    public function testBaseException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Base parameter needs to be string');

        $fb = new Firebase([],'');
    }

    public function testUrlException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The base URL firebase is not valid');

        $fb = new Firebase('firebase','');
    }

    public function testTokenException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Token parameter needs to be string');

        $fb = new Firebase('https://example.com/',[]);
    }
}