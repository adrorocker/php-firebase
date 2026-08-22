# Using it

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
