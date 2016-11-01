<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase;

use InvalidArgumentException;
use PhpFirebase\Clients\GuzzleClient;
use PhpFirebase\Interfaces\FirebaseInterface;
use PhpFirebase\Interfaces\ClientInterface;

/**
 * Firebase.
 *
 * @package PhpFirebase
 * @since 0.1.0
 */
class Firebase implements FirebaseInterface
{
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
     * Client
     *
     * @var \PhpFirebase\Interfaces\ClientInterface
     */
    protected $client = null;

    /**
     * Response
     *
     * @var mixed
     */
    protected $response = null;

    /**
     * Set the base path for Firebase endpont
     * and the token to authenticate 
     *
     * @param string $base The base endpoint
     * @param string $token The token
     * @param \PhpFirebase\Interfaces\ClientInterface|null $client Client to make the request
     */
    public function __construct($base, $token, ClientInterface $client = null)
    {
        if (!is_string($base)) {
            throw new InvalidArgumentException("Base parameter needs to be string", 1);
        }
        if (!is_string($token)) {
            throw new InvalidArgumentException("Token parameter needs to be string", 1);
        }
        
        $parts = parse_url($base);

        if (!isset($parts['scheme']) || !isset($parts['host'])) {
            throw new InvalidArgumentException("The base URL $base is not valid", 1);
        }
        
        $this->base = rtrim($base,'/');
        $this->token = (string) $token;

        if (!$client) {
            $client = new GuzzleClient([
                'base' => $this->base,
                'token' => $this->token,
            ]);
        }

        $this->setClient($client);
    }

    /**
     * GET request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return object
     */
    public function get($endpoint, $query = [])
    {
        $endpoint = $this->buildUri($endpoint, $query);
        $headers = $this->buildHeaders();

        $this->response = $this->client->get($endpoint,$headers);

        return $this->response;
    }

    /**
     * POST request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function post($endpoint, $data, $query = [])
    {
        $endpoint = $this->buildUri($endpoint, $query);
        $headers = $this->buildHeaders();
        $data = $this->prepareData($data);

        $this->response = $this->client->post($endpoint,$data,$headers);

        return $this->response;
    }

    /**
     * PUT request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function put($endpoint, $data, $query = [])
    {
        $endpoint = $this->buildUri($endpoint, $query);
        $headers = $this->buildHeaders();
        $data = $this->prepareData($data);

        $this->response = $this->client->put($endpoint,$data,$headers);

        return $this->response;
    }

    /**
     * PATCH request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function patch($endpoint, $data, $query = [])
    {
        $endpoint = $this->buildUri($endpoint, $query);
        $headers = $this->buildHeaders();
        $data = $this->prepareData($data);

        $this->response = $this->client->patch($endpoint,$data,$headers);

        return $this->response;
    }

    /**
     * DELETE request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return object
     */
    public function delete($endpoint, $query = [])
    {
        $endpoint = $this->buildUri($endpoint, $query);
        $headers = $this->buildHeaders();

        $this->response = $this->client->delete($endpoint,$headers);

        return $this->response;
    }

    /**
     * Get response
     *
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get client
     *
     * @return \PhpFirebase\Interfaces\ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get base endpoint
     *
     * @return string
     */
    public function getBase()
    {
        return $this->base;
    }

    /**
     * Set the client
     *
     * @param \PhpFirebase\Interfaces\ClientInterface
     */
    protected function setClient(ClientInterface $client)
    {
        $this->client = $client;
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
}
