[PHP-Firebase](https://github.com/adrorocker/php-firebase)
===================================

A PHP SDK for Firebase REST API.

[![Build status][Master image]][Master]

-----------------------------------

## Installation

```
composer require adrorocker/php-firebase
```
-----------------------------------

## Usage

```php
require '../vendor/autoload.php';

use PhpFirebase\Firebase;

// Base endpoint
$base = 'https://hey-123.firebaseio.com/somesubendpoint';

// Auth token
$token = 'a1b2c3d4e5f6g7h8i9';

$firebase = new Firebase($base,$token);

// Unique ID
$id = (new \DateTime())->getTimestamp();

$data = ['key' => 'value']; // Or even just a string

// Make a PUT request and retive the response
$put = $firebase->put('/logs/'.$id, $data)->getResponse();

// Make a GET request and retive the response, you will see all the logs
$get = $firebase->get('/logs')->getResponse();
```
-----------------------------------

## Authors:

[Alejandro Morelos](https://github.com/adrorocker). 

  [Master]: https://travis-ci.org/adrorocker/php-firebase/
  [Master image]: https://travis-ci.org/adrorocker/php-firebase.svg?branch=master