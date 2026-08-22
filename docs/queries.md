# Querying

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
  be kept in step, which is what [transactions](usage.md#transactions) and batched writes
  are for.

Use the typed objects for what they are good at: making the record you already
decided to store safe to work with. Not for pretending it is a table.
