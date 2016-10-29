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

/**
 * Guzzle Client.
 *
 * @package PhpFirebase
 * @subpackage Clients
 * @since 0.1.0
 */
class GuzzleClient implements ClientInterface
{
    /**
     * Guzzle client
     *
     * @var \GuzzleHttp\Client
     */
    protected $guzzle;

    /**
     * Base endpoint
     *
     * @var string
     */
    protected $base;

    /**
     * Token
     *
     * @var string
     */
    protected $token;

    /**
     * Set the base path for Firebase endpont
     * the token to authenticate and the guzzle client
     *
     * @param string $base The base endpoint
     * @param string $token The token
     * @param \PhpFirebase\Interfaces\ClientInterface|null $client Client to make the request
     */
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

    /**
     * Create a new GET reuest
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return array
     */
    public function get($endpoint, $query = [])
    {
        $request = new Request('GET',$this->buildUri($endpoint, $query), $this->buildHeaders());

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new POST reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function post($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('POST',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new PUT reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function put($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('PUT',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new PATCH reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function patch($endpoint, $data, $query = [])
    {
        $data = $this->prepareData($data);

        $request = new Request('PATCH',$this->buildUri($endpoint, $query),$this->buildHeaders(),$data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new DELETE reuest
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return array
     */
    public function delete($endpoint, $query = [])
    {
        $request = new Request('DELETE',$this->buildUri($endpoint, $query), $this->buildHeaders());

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Convert array|string to json
     *
     * @param array $data Data to be converted
     *
     * @return array
     */
    protected function prepareData($data = [])
    {
        return json_encode($data);
    }

    /**
     * Create a standard uri based on the end point 
     * and add the auth token
     *
     * @param string $endpoint The sub endpoint
     * @param array $options Extra options to be added
     *
     * @return string
     */
    protected function buildUri($endpoint, $options = [])
    {
        if ($this->token !== '') {
            $options['auth'] = $this->token;
        }

        return $this->base . '/' . ltrim($endpoint, '/') . '.json?' . http_build_query($options, '', '&');
    }

    /**
     * Build all headers
     *
     * @param array $extraHeaders Extra headers to be added
     *
     * @return array
     */
    protected function buildHeaders($extraHeaders = [])
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type: application/json',
        ];

        return array_merge($headers, $extraHeaders);
    }

    /**
     * Handle the response
     *
     * @param \GuzzleHttp\Psr7\Response $response The response
     *
     * @return array
     */
    private function handle(Response $response)
    {
        $stream = stream_for($response->getBody());

        $data = json_decode($stream->getContents());

        return $data;
    }
    
}
