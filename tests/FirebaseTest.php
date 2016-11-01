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
use PhpFirebase\Clients\FakeGuzzle;
use PhpFirebase\Clients\GuzzleClient;

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

        $response = $fb->get('/');

        $this->assertNull($response);

        $response = $fb->post('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->put('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->patch('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->delete('/');

        $this->assertNull($response);

        $same = $fb->getResponse();

        $this->assertEquals($response,$same);
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

    public function testGuzzleClient()
    {
        $base = 'https://example.com/';
        $token = 's0m3t0k3n';
        $guzzle = new FakeGuzzle;

        $client = new GuzzleClient([],$guzzle);

        $fb = new Firebase($base,$token,$client);

        $response = $fb->get('/');

        $this->assertNull($response);

        $response = $fb->post('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->put('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->patch('/',['key'=>'value']);

        $this->assertNull($response);

        $response = $fb->delete('/');

        $this->assertNull($response);

        $same = $fb->getResponse();

        $this->assertEquals($response,$same);
    }
}