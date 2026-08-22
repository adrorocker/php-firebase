# Getting started

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
