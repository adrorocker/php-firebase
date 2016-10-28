<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase\Clients;

use InvalidArgumentException;
use PhpFirebase\Interfaces\ClientInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use function GuzzleHttp\Psr7\stream_for;

class GuzzleClient implements ClientInterface
{
    protected $guzzle;

    protected $base;

    protected $token;

    public function __construct(array $options = [])
    {
        if (!isset($options['base'])) {
            throw new InvalidArgumentException("Missign base path");
        }

        if (!isset($options['token'])) {
            throw new InvalidArgumentException("Missign token");
        }

        $this->base = $options['base'];
        $this->token = $options['token'];
        
        $this->guzzle = new HttpClient($options);
    }

    public function get($endpoint, $query = [])
    {
        $request = new Request('GET',$this->buildUri($endpoint, $query), $this->buildHeaders());

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function post($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('POST',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function put($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('PUT',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function patch($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('PATCH',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    public function delete($endpoint, $query = [])
    {
        $request = new Request('DELETE',$this->buildUri($endpoint, $query), $this->buildHeaders());

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    protected function prepareData($data = [])
    {
        return json_encode($data);
    }

    protected function buildUri($endpoint, $options = [])
    {
        if ($this->token !== '') {
            $options['auth'] = $this->token;
        }

        return $this->base . '/' . ltrim($endpoint, '/') . '.json?' . http_build_query($options, '', '&');
    }

    protected function buildHeaders($extraHeaders = [])
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type: application/json',
        ];

        return array_merge($headers, $extraHeaders);
    }

    private function handle(Response $response, $default = null)
    {
        $stream = stream_for($response->getBody());

        $data = json_decode($stream->getContents());

        return $data;
    }
    
}
