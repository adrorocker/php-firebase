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
 * Client Interface.
 *
 * @package PhpFirebase
 * @subpackage Interfaces
 * @since 0.1.0
 */
interface ClientInterface
{
    /**
     * GET request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return array
     */
    public function get($endpoint, $query = []);

    /**
     * POST request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function post($endpoint, $data, $query = []);

    /**
     * PUT request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function put($endpoint, $data, $query = []);

    /**
     * PATCH request
     *
     * @param string $endpoint The sub endpoint
     * @param string|array $data The data to be submited
     * @param array $query Query parameters
     *
     * @return array
     */
    public function patch($endpoint, $data, $query = []);

    /**
     * DELETE request
     *
     * @param string $endpoint The sub endpoint
     * @param array $query Query parameters
     *
     * @return array
     */
    public function delete($endpoint, $query = []);
}
