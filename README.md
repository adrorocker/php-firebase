[PHP-Firebase](https://github.com/adrorocker/php-firebase)
===================================

A PHP SDK for Firebase REST API.

[![Build status][Master image]][Master]
[![Coverage Status][Master covarage image]][Master covarage]

-----------------------------------

## Installation

```
composer require adrorocker/php-firebase
```

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

// Set the data (body of the request).
$data = ['key' => 'value']; // The data could be even just a string

// Make a PUT request, the response is return
$put = $firebase->put('/logs/'.$id, $data);

// Make a GET request, the response is return, 
// you will have all the logs in the $get variable 
$get = $firebase->get('/logs');
```

## Authors:

[Alejandro Morelos](https://github.com/adrorocker). 

  [Master]: https://travis-ci.org/adrorocker/php-firebase/
  [Master image]: https://travis-ci.org/adrorocker/php-firebase.svg?branch=master
  [Master covarage]: https://coveralls.io/github/adrorocker/php-firebase
  [Master covarage image]: https://coveralls.io/repos/github/adrorocker/php-firebase/badge.svg?branch=master
