<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase\Interfaces;

/**
 * Base interface.
 *
 * @package PhpFirebase
 * @subpackage Interfaces
 * @since 0.1.0
 */
interface FirebaseInterface
{
    /**
     * Set the base path for Firebase endpont
     * and the token to authenticate 
     *
     * @param string $base The base endpoint
     * @param string $token The token
     */
    public function __construct($base, $token);

    /**
     * GET request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return object
     */
    public function get($endpoint, $query = []);

    /**
     * POST request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function post($endpoint, $data, $query = []);

    /**
     * PUT request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function put($endpoint, $data, $query = []);

    /**
     * PATCH request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return object
     */
    public function patch($endpoint, $data, $query = []);

    /**
     * DELETE request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return object
     */
    public function delete($endpoint, $query = []);

    /**
     * Get response
     *
     * @return mixed
     */
    public function getResponse();

    /**
     * Get client
     *
     * @return \PhpFirebase\Interfaces\ClientInterface
     */
    public function getClient();

    /**
     * Get base endpoint
     *
     * @return string
     */
    public function getBase();
}
