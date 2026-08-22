# PHP-Firebase

Entities and repositories for the **Firebase Realtime Database**, so your
application code deals in typed objects instead of nested arrays. Cloud
Firestore works through the same API if you need it.

```php
$users = new UserRepository($connection);

$ada = new User();
$ada->firstName = 'Ada';
$ada->lastName = 'Lovelace';

$ada = $users->save($ada);   // now carries its id

$admins = $users->query()
    ->whereEquals('role', 'admin')
    ->limit(10)
    ->fetch();               // array<string, User>
```

> **Version 1.0 is a rewrite** and breaks with 0.x deliberately. If you are
> coming from an older version, read [the upgrade guide](docs/upgrading.md),
> and [the short history](#a-short-history) for why.

## Installation

```
composer require adrorocker/php-firebase
```

Requires PHP 8.3 or newer. The package itself pulls in four things - a mapper
and three PSR interface packages - and then you add a client for the database
you actually use:

```bash
# Realtime Database
composer require kreait/firebase-php

# Firestore
composer require google/auth
```

Neither is a hard dependency, so you never install the one you are not using. If
you already have an authenticated PSR-18 client, you need neither.

## What this is

This library is the mapping layer, not a Firebase SDK. It answers "how do I turn
these records into `User` objects and back" and leaves transport, credentials and
retries to a real SDK underneath.

- **`Entity`** - a record, with typed properties instead of `stdClass`.
- **`Repository`** - reads and writes one collection of them, including batched
  writes and optimistic transactions.
- **`Query`** - an immutable query builder returning hydrated entities.
- **`Connection`** - the port the first three talk to. Two adapters ship: the
  Realtime Database (via `kreait/firebase-php`) and Firestore (over its REST API,
  no `ext-grpc`). The entity layer itself has no Firebase in it, so swapping
  backends does not touch your entities, repositories or queries.

### What it is not

**It is not an ORM, and Firebase is not a relational database.** That sentence is
the most useful thing on this page, because everything below it is shaped like
Doctrine and will happily let you pretend otherwise.

There are no joins. There are no foreign keys. There is no query planner that
will rescue a design built around them. A `Repository` here is a typed door onto
one path in a document store - not a table, and not something you can join to
another one.

**It does not stream, and that is deliberate.** The Realtime Database can push
changes over `text/event-stream`, and PHP can consume them perfectly well given
a runtime that stays alive - ReactPHP, Amp, Swoole, FrankenPHP in worker mode.
But holding a connection open, reconnecting it, and backing off when it drops is
infrastructure, and this package maps entities. Subscribe in whatever process
you already run for long-lived work, and use this to turn what arrives into
objects.

Note that "Firebase" means something broader in 2026 than it did when this
package was written. The Realtime Database and Cloud Firestore are two separate
databases under the same brand, with different APIs and different query models.
The Realtime Database is what this package was built for and is still supported
by Google; Firestore is what Google recommends for new projects, which is why
both are here.

Reading a record is delegated to [cuyz/valinor](https://github.com/CuyZ/Valinor),
which already handles enums, dates, nested objects and union types better than
anything worth hand-rolling. Writing is not: this library leaves private
properties and nulls out of the payload, and those are not valinor's defaults.

## Documentation

- [Getting started](docs/getting-started.md) - entities, repositories, connecting
- [Reading and writing](docs/usage.md) - saving, batches, transactions, server values
- [Queries and modelling](docs/queries.md) - the query builder, and what each backend can run
- [Errors](docs/errors.md) - what gets thrown and what it carries
- [Extending it](docs/extending.md) - other backends, other constraints, your own query methods
- [Upgrading from 0.x](docs/upgrading.md)

## A short history

This is one of my oldest repositories. I wrote it back in 2016, when talking to
the Firebase Realtime Database from PHP mostly meant writing the REST calls
yourself, and it did that job well enough that it ended up inside a real product
at work. In 2018 I added an entity and repository layer on top of it. Then I
stopped touching it.

It sat like that for years. Firebase moved on and PHP moved on, and the code did
not. It still asked for PHP 5.6 and Guzzle 6, it authenticated with a database
secret that Google has since deprecated, and it called a Guzzle function that
does not exist any more - so somewhere along the way a fresh install quietly
stopped working at all.

I kept the repository anyway. It was one of my first open source projects and I
was fond of it.

This version is that fondness acted on. The old HTTP client is gone; it was never
the good part, and `kreait/firebase-php` does that job properly. What I kept is
the entity layer, rewritten from scratch - typed properties, a real port
underneath, and Firestore alongside the Realtime Database.

Preserved and reborn out of love for it, and in the hope that it is useful again.

## Development

```bash
composer install
composer test      # phpunit
composer analyse   # phpstan, level 8
composer cs        # php-cs-fixer, dry run
```

## License

MIT. See [LICENSE](LICENSE).
