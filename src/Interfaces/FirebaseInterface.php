<?php
/**
 * PHP-Firebase
 *
 * @link      https://github.com/adrorocker/php-firebase
 * @copyright Copyright (c) 2016 Adro Rocker
 * @author    Adro Rocker <alejandro.morelos@jarwebdev.com>
 */
namespace PhpFirebase\Interfaces;

use PhpFirebase\Interfaces\ClientInterface;

interface FirebaseInterface
{
    public function __construct($base, $token);

    public function get($endpoint);

    public function post($endpoint, $data);

    public function put($endpoint, $data);

    public function patch($endpoint, $data);

    public function delete($endpoint);
}