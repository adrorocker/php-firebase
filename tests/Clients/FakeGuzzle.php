<?php
/**
 * PHP-Firebase.
 *
 * @link      https://github.com/adrorocker/php-firebase
 *
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */

namespace PhpFirebase\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class FakeGuzzle extends Client
{
    public function __construct(array $config = [])
    {
        $mock = new MockHandler([
                        new Response(200, [], 'Hello Firebase GET'),
                        new Response(200, [], 'Hello Firebase POST'),
                        new Response(200, [], 'Hello Firebase PUT'),
                        new Response(200, [], 'Hello Firebase PATCH'),
                        new Response(200, [], 'Hello Firebase DELETE'),
                        new Response(202, ['Content-Length' => 0]),
                        new RequestException('Error Communicating with Server', new Request('GET', 'test')),
                    ]);

        $handler = HandlerStack::create($mock);

        parent::__construct(['handler' => $handler]);
    }
}
