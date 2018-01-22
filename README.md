[PHP-Firebase](https://github.com/adrorocker/php-firebase)
===================================

A PHP SDK for Firebase REST API.

[![Build status][Master image]][Master]
[![Coverage Status][Master covarage image]][Master covarage]
[![Latest Stable Version][Stable version image]][Stable version]
[![License][License image]][License]

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


## Extras

Now [PHP-Firebase](https://github.com/adrorocker/php-firebase) include a simple way to save and retrieve _Entities_ using repositories.

You can use them like this:

* Create an _entity_ class

```php
// app/Model/User/User.php
<?php

namespace App\Model\User;

use PhpFirebase\Entities\Entity;

class User extends Entity
{
    protected $id;

    public $firstName;

    public $lastName;
}

```

* Create a _repository_ class

```php
// app/Model/User/UserRepository.php
<?php

namespace App\Model\User;

use PhpFirebase\Entities\Repository\Repository;

class UserRepository extends Repository
{
    public function __construct()
    {
        // Base endpoint
        $base = 'https://hey-123.firebaseio.com/somesubendpoint';
        // Auth token
        $token = 'a1b2c3d4e5f6g7h8i9';

        $this->class = User::class;

        parent::__construct($base, $token, '/users');
    }
}

```

* Usage

```php
require '../vendor/autoload.php';

$repo =  new UserRepository();
// Create user
$user = new User([
    'id' => 1,
    'firstName' => 'Adro',
    'lastName' => 'Rocker',
]);
$user = $repo->store($user); // $user will be an instance of App\Model\User

// Update user
// You can get or assign values to an entity property using a method named as the property name.
$user->lastName('Rocks'); // setting $lastName to be 'Rocks'.
$lastName = $user->lastName(); // getting $lastName, $lastName has the value 'Rocks'.
$user = $repo->store($user);

// Find user
$user = $repo->find(1); // $user will be an instance of App\Model\User

```

## Authors:

[Alejandro Morelos](https://github.com/adrorocker). 

  [Master]: https://travis-ci.org/adrorocker/php-firebase/
  [Master image]: https://travis-ci.org/adrorocker/php-firebase.svg?branch=master
  [Master covarage]: https://coveralls.io/github/adrorocker/php-firebase
  [Master covarage image]: https://coveralls.io/repos/github/adrorocker/php-firebase/badge.svg?branch=master
  [Stable version]: https://packagist.org/packages/adrorocker/php-firebase
  [Stable version image]: https://poser.pugx.org/adrorocker/php-firebase/v/stable
  [License]: https://packagist.org/packages/adrorocker/php-firebase
  [License image]: https://poser.pugx.org/adrorocker/php-firebase/license
