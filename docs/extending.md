# Extending it

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
