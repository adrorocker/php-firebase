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
> coming from an older version, read [UPGRADING.md](UPGRADING.md), and
> [the short history](#a-short-history) for why.

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

## Modelling for Firebase, not against it

Repositories and entities come from the relational world, where you model the
domain and then persist it. Firebase is the other way round: you model for the
reads you intend to do, and you denormalise on purpose.

So resist the pull toward one repository per noun with references between them.
Fetching an order, then its customer, then each of its line items is three round
trips for something Firebase would rather you stored as one document. **Entities
nest** - an `Order` holding `LineItem` objects and a `Money` value object is a
single record, and that is usually the right shape:

```php
$order = $orders->get('order-1');   // one read, whole aggregate
$order->lines[0]->price->amount;
```

Two consequences worth planning for:

- **A query cannot span collections.** If you need data together, store it
  together. Duplicating a customer's name onto the order is normal here, not a
  modelling failure.
- **Reads are cheap and writes are the constraint.** Denormalised copies have to
  be kept in step, which is what [transactions](#transactions) and batched writes
  are for.

Use the typed objects for what they are good at: making the record you already
decided to store safe to work with. Not for pretending it is a table.

## Getting started

### 1. Describe your entity

Public and protected properties are persisted. Private ones never are, which is
how the base class keeps its own `$id` out of the stored record: the key **is**
the id, so storing it twice only lets the two drift apart.

```php
use PhpFirebase\Entity;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}

final class User extends Entity
{
    public string $firstName = '';
    public string $lastName = '';
    public ?int $age = null;
    public Role $role = Role::Member;          // backed enums are converted
    public ?DateTimeImmutable $joinedAt = null; // as are dates
}
```

Unknown keys in the stored record are ignored rather than fatal, which keeps old
records readable after you add a field.

Entities are yours; `User` here is only an example. Any class works, including
aggregates holding plain value objects that know nothing about persistence:

```php
final readonly class Money
{
    public function __construct(public int $amount, public string $currency) {}
}

final class Order extends Entity
{
    public OrderStatus $status = OrderStatus::Pending;
    public ?Money $total = null;
    /** @var list<LineItem> */
    public array $lines = [];
}
```

#### Constructors

There are two shapes, and both work:

- **No constructor** - properties are filled directly. Simplest, and the kinder
  choice for records that gain fields over time.
- **Every persisted property promoted into the constructor** - the object can
  never exist half-built, which is what you want for an aggregate with
  invariants to protect.

A constructor covering only *some* persisted properties is **refused with an
error**, because the rest would silently keep their defaults on read and then
overwrite the stored values on the next save:

```php
final class Broken extends Entity
{
    public string $note = '';                          // never filled
    public function __construct(public string $title = '') {}
}
// InvalidEntity: Broken declares a constructor that does not cover $note ...
```

### 2. Describe the collection

```php
use PhpFirebase\Repository;

/** @extends Repository<User> */
final class UserRepository extends Repository
{
    protected function collection(): string
    {
        return 'users';
    }

    protected function entityClass(): string
    {
        return User::class;
    }
}
```

For the simple case, skip the subclass entirely:

```php
$users = new EntityRepository($connection, 'users', User::class);
```

### 3. Connect it

**Realtime Database**, through `kreait/firebase-php`:

```php
use Kreait\Firebase\Factory;
use PhpFirebase\Database\RealtimeDatabaseConnection;

$database = (new Factory())
    ->withServiceAccount('/path/to/service-account.json')
    ->withDatabaseUri('https://your-project.firebaseio.com')
    ->createDatabase();

$users = new UserRepository(new RealtimeDatabaseConnection($database));
```

**Firestore**, over its REST API:

```php
use PhpFirebase\Database\Firestore\FirestoreConnectionFactory;

$connection = FirestoreConnectionFactory::fromServiceAccount('/path/to/service-account.json');
// or, on Cloud Run / App Engine / GCE / after `gcloud auth application-default login`:
$connection = FirestoreConnectionFactory::fromApplicationDefaultCredentials('your-project-id');

$users = new UserRepository($connection);
```

Either way credentials come from a service account or Application Default
Credentials - not the legacy database secret 0.x used.

## Using it

```php
// Create: no id yet, so the backend allocates one
$ada = $users->save($ada);
$ada->id(); // '-NxAbc123...'

// Read
$users->find('-NxAbc123...');   // ?User
$users->get('-NxAbc123...');    // User, or throws EntityNotFound
$users->all();                // array<string, User>, keyed by id
$users->count();

// Update the whole record, or just part of it
$ada->lastName = 'King';
$users->save($ada);
$users->update($ada, ['lastName' => 'King']);

// Delete
$users->delete($ada);
$users->deleteAll();
```

Entities are treated as immutable where it matters: `save()` and `withId()` return
a copy rather than mutating the instance you passed in.

### Saving in batches

`saveMany()` writes entities that already have an id in **one** round trip, using
a multi-path update on the Realtime Database and a batched commit on Firestore:

```php
$users->saveMany([
    $ada->withId('user-1'),
    $grace->withId('user-2'),
]);   // one request, not two
```

Entities without an id are created one at a time, because only the backend can
allocate their ids. Mixing both in a single call is fine.

### Subcollections and nested paths

A collection is a path, so nesting works on both backends:

```php
final class OrderRepository extends Repository
{
    public function __construct(Connection $connection, private readonly string $userId)
    {
        parent::__construct($connection);
    }

    protected function collection(): string
    {
        return sprintf('users/%s/orders', $this->userId);
    }

    protected function entityClass(): string
    {
        return Order::class;
    }
}
```

On Firestore that is a real subcollection, queried against its parent document.
On the Realtime Database it is simply a deeper node in the tree.

## Transactions

Reading a value, changing it and writing it back is unsafe if anything else
might be doing the same. `modify()` does it properly:

```php
$accounts->modify('ada', function (Account $account): Account {
    $account->balance -= 10;

    return $account;
});
```

The work runs against a consistent snapshot and is committed only if nothing it
read has changed. If another writer got there first, it runs **again** with the
newer data - so keep it repeatable, and send the receipt email after it returns
rather than inside it. After five losing attempts it gives up with
`TransactionConflict`.

For more than one record, take the transaction itself:

```php
$orders->transaction(function (EntityTransaction $orders): void {
    $order = $orders->get('order-1');
    $order->status = OrderStatus::Shipped;
    $orders->save($order);
});
```

Read through the handle you are given, not through the repository: only reads
made there are watched for concurrent changes.

**The guarantee differs by backend, and the difference is worth knowing.**
Firestore commits every write together or none of them. The Realtime Database
has no such thing - it checks each write individually against the version it
read, so a transaction touching two records can leave the first written and the
second refused. For the single-record case both give you exactly what you want.

## Server timestamps and removing fields

Some values are decided by the server, not by you:

```php
use PhpFirebase\Database\Sentinel;

$order->updatedAt = Sentinel::ServerTimestamp;  // the server's clock, not yours
$orders->save($order);

$orders->update($order, ['couponCode' => Sentinel::Remove]);  // gone, not null
```

`Remove` is worth the distinction: storing `null` and having no field at all are
different states, and only the second one stops the field existing.

`save()` returns the entity you handed it plus its id - it does not read back,
so that copy still holds the sentinel rather than the time the server chose. It
encodes as `null` until you re-read, which keeps an entity holding one safe to
`json_encode`. Read the record again when you need the resolved value.

## Querying

Queries are immutable, so a partly built one is safe to keep and branch from:

```php
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;

$adults = $users->query()->where('age', Operator::GreaterThanOrEqual, 18);

$firstTen = $adults->limit(10)->fetch();
$oldest   = $adults->orderBy('age', Direction::Descending)->first();
```

`fetch()` returns entities keyed by id, `first()` returns one or `null`, and
`count()` returns how many matched.

### What each backend can actually run

This is where the two backends genuinely differ, and the library does not
pretend otherwise.

| | Realtime Database | Firestore |
| --- | --- | --- |
| Filter on several fields | No - throws `UnsupportedQuery` | Yes, needs a composite index |
| Sort by a field other than the filtered one | No - throws `UnsupportedQuery` | Yes |
| Sort descending | Emulated client side | Native |
| `Operator::In`, `Operator::Contains` | No - throws `UnsupportedQuery` | Yes |
| `count()` | Reads the matching records | Native aggregation query |

#### The Realtime Database

The Realtime Database is not a query engine. It filters and sorts on **one field
per query**, and that field must be indexed in your security rules. Rather than
quietly returning the wrong rows, the adapter throws `UnsupportedQuery`:

```php
$users->query()
    ->whereEquals('role', 'admin')
    ->whereEquals('age', 36)
    ->fetch();
// UnsupportedQuery: The Realtime Database cannot run this query:
// it can filter on one field only, and this query filters on role, age.
```

Three details it handles for you. Filtering implies ordering on the same field,
so you do not have to say it twice. Because the database only ever sorts
ascending, a descending query is served by reading the matching window and
reversing it. And a `limit()` with nothing to order by is given one - the
database rejects a bare limit with *"orderBy must be defined when other query
parameters are defined"*, and taking the first n of a keyed collection means in
key order.

#### Firestore

Firestore can express everything `Criteria` describes, so nothing is refused. It
will instead ask for an index the first time you run a compound query, and the
error it returns contains a link that creates it.

Two things worth knowing about this adapter:

- **Dates are stored as ISO-8601 strings**, not Firestore timestamps. They sort
  and compare correctly because ISO-8601 is lexicographically ordered, and they
  are read back into `DateTimeImmutable` either way.
- **`deleteAll()` is not atomic.** Firestore has no truncate - a collection is
  only the documents in it - so it reads the collection and deletes each
  document. Empty a large collection from a batched job instead.

## When something goes wrong

Everything this package throws implements
`PhpFirebase\Exception\PhpFirebaseException`, so it can all be caught together:

| Exception | Means |
| --- | --- |
| `EntityNotFound` | `get()` found nothing under that id |
| `InvalidEntity` | a record could not be mapped, or a value could not be stored |
| `UnsupportedQuery` | this backend cannot express the query, and will not guess |
| `TransactionConflict` | a transaction lost the race on every attempt |
| `BackendError` | the backend refused the request |

`BackendError` carries the HTTP `status` and Google's own `googleStatus`
(`FAILED_PRECONDITION`, `PERMISSION_DENIED`, ...), plus the message the API sent -
which matters, because the useful ones are specific:

```php
try {
    $tasks->query()->whereEquals('status', 'open')->orderBy('due')->fetch();
} catch (BackendError $e) {
    $e->googleStatus;   // FAILED_PRECONDITION
    $e->reason;         // "The query requires an index. You can create it here: https://..."
}
```

That link creates the index. Firestore reports failures on its streaming
endpoints inside a JSON *array* rather than an object, and missing that shape
turns the most useful error either database produces into "Bad Request" - so
both shapes are unwrapped.

A `TransactionConflict` says what the final attempt actually failed with, rather
than only that it gave up. Failures that are not conflicts are raised
immediately instead of being retried, since retrying them cannot help.

## Extending it

The package is deliberately small, and the parts it leaves out are reachable
from outside rather than only by forking.

### A different backend

Implement `Connection` - nine methods over a collection of records, plus
`transaction()`. Nothing above it knows what Firebase is, so the same entities,
repositories and queries run against whatever you write. The test suite does
exactly this with an in-memory implementation, and a parity test asserts the
same entity comes back identically from each.

### A constraint this package does not model

Cursors are the honest example. Firestore has them; the Realtime Database
cannot express one, so a neutral API for them would be a Firestore feature in a
backend-neutral coat. Carry it as an extra instead:

```php
$criteria = $criteria->orderBy('age')->withExtra('startAfter', [42]);
```

A connection declares the extras it honours, and **must refuse any it does not**
- otherwise a query silently means something different depending on where it
runs:

```php
final class CursorFirestoreConnection extends FirestoreConnection
{
    protected function supportedExtras(): array
    {
        return ['startAfter'];
    }
}

final readonly class CursorQueryTranslator extends QueryTranslator
{
    public function translate(string $collection, Criteria $criteria): array
    {
        $query = parent::translate($collection, $criteria);

        if (is_array($cursor = $criteria->extra('startAfter'))) {
            $query['startAt'] = ['values' => ..., 'before' => false];
        }

        return $query;
    }
}
```

`FirestoreConnection` takes its `QueryTranslator` and `ValueEncoder` as
constructor arguments, so swapping either needs no changes here. There is a
working version of the above in `tests/ExtensibilityTest.php`.

### Query methods of your own

Subclass `Query`, and have the repository hand out your type:

```php
/** @extends Query<User> */
final readonly class UserQuery extends Query
{
    public function adults(): static
    {
        return $this->where('age', Operator::GreaterThanOrEqual, 18);
    }
}

final class UserRepository extends Repository
{
    // A narrower return type is allowed, and is what callers see.
    public function query(): UserQuery
    {
        return new UserQuery($this->connection, $this->collection(), $this->entityClass());
    }
}

$users->query()->adults()->limit(10)->fetch();
```

Override `with()` in the subclass if you add state of your own; the chain keeps
returning your type either way.

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
