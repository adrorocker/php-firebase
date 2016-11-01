<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase\Clients;

use PhpFirebase\Interfaces\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use function GuzzleHttp\Psr7\stream_for;

class FakeClient implements ClientInterface
{

    protected $mock;

    protected $handler;

    protected $guzzle;

    public function __construct()
    {
        $this->mock = new MockHandler([
                        new Response(200, [],'Hello Firebase GET'),
                        new Response(200, [],'Hello Firebase POST'),
                        new Response(200, [],'Hello Firebase PUT'),
                        new Response(200, [],'Hello Firebase PATCH'),
                        new Response(200, [],'Hello Firebase DELETE'),
                        new Response(202, ['Content-Length' => 0]),
                        new RequestException("Error Communicating with Server", new Request('GET', 'test')),
                    ]);

        $this->handler = HandlerStack::create($this->mock);

        $this->guzzle = new Client(['handler' => $this->handler]);
    }

    public function get($endpoint, $headers = [])
    {
        $request = new Request('GET', $endpoint, $headers);
        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function post($endpoint, $data, $headers = [])
    {
        $request = new Request('POST', $endpoint, $headers, $data);
        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function put($endpoint, $data, $headers = [])
    {
        $request = new Request('PUT', $endpoint, $headers, $data);
        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function patch($endpoint, $data, $headers = [])
    {
        $request = new Request('PATCH', $endpoint, $headers, $data);
        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function delete($endpoint, $headers = [])
    {
        $request = new Request('DELETE', $endpoint, $headers);
        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    private function handle(Response $response)
    {
        $stream = stream_for($response->getBody());

        $data = json_decode($stream->getContents());

        return $data;
    }
}