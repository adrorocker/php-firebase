<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2018 Adro Rocker
 * @author    Adro Rocker <mes@adro.rocks>
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
     * Set the the guzzle client
     *
     * @param array $options The options to set the defaul
     * @param Object|null $client Client to make the requests
     */
    public function __construct(array $options = [], $client = null)
    {
        if (!$client) {
            $client = new HttpClient($options);
        }
        
        $this->guzzle = $client;
    }

    /**
     * Create a new GET reuest
     *
     * @param string $endpoint The sub endpoint
     * @param array $headers Request headers
     *
     * @return array
     */
    public function get($endpoint, $headers = [])
    {
        $request = new Request('GET', $endpoint, $headers);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new POST reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $headers Request headers
     *
     * @return array
     */
    public function post($endpoint, $data, $headers = [])
    {
        $request = new Request('POST', $endpoint, $headers, $data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new PUT reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $headers Request headers
     *
     * @return array
     */
    public function put($endpoint, $data, $headers = [])
    {
        $request = new Request('PUT', $endpoint, $headers, $data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new PATCH reuest
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $headers Request headers
     *
     * @return array
     */
    public function patch($endpoint, $data, $headers = [])
    {
        $request = new Request('PATCH', $endpoint, $headers, $data);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
    }

    /**
     * Create a new DELETE reuest
     *
     * @param string $endpoint The sub endpoint
    * @param array $headers Request headers
     *
     * @return array
     */
    public function delete($endpoint, $headers = [])
    {
        $request = new Request('DELETE', $endpoint, $headers);

        $response = $this->guzzle->send($request);

        return $this->handle($response);
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
