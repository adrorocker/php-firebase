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

class Firebase implements FirebaseInterface
{
    protected $base;

    protected $token;

    protected $client = null;

    protected $response = null;

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

    public function get($endpoint, $query = [])
    {
        $this->response = $this->client->get($endpoint,$query);

        return $this;
    }

    public function post($endpoint, $data, $query = [])
    {
        $this->response = $this->client->post($endpoint,$data);

        return $this;
    }

    public function put($endpoint, $data, $query = [])
    {
        $this->response = $this->client->put($endpoint,$data);

        return $this;
    }

    public function patch($endpoint, $data, $query = [])
    {
        $this->response = $this->client->patch($endpoint,$data);

        return $this;
    }

    public function delete($endpoint, $query = [])
    {
        $this->response = $this->client->delete($endpoint);

        return $this;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function getBase()
    {
        return $this->base;
    }

    protected function setClient(ClientInterface $client)
    {
        $this->client = $client;
    }

}