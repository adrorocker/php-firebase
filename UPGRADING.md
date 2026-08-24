# Upgrading

## 0.x to 1.0

1.0 is a rewrite. The entity and repository layer survives with a changed API;
everything below it is gone.

### Why

0.x shipped its own HTTP client and authenticated by putting a Firebase database
secret in the query string. Google deprecated that credential, and the code had
rotted besides: it required PHP 5.6, Guzzle 6, and a `GuzzleHttp\Psr7\stream_for()`
function that no longer exists in Guzzle's PSR-7 package, so a fresh install
could not run at all. One repository also disabled TLS certificate verification.

Rather than patch a client that was never the valuable part, 1.0 hands transport
and credentials to `kreait/firebase-php` and keeps the mapping layer.

### What was removed

| 0.x | 1.0 |
| --- | --- |
| `PhpFirebase\Firebase` | Gone. Use a `Connection`, or `kreait/firebase-php` directly for raw access. |
| `PhpFirebase\Clients\GuzzleClient` | Gone. |
| `PhpFirebase\Interfaces\ClientInterface` | Replaced by `PhpFirebase\Database\Connection`. |
| `PhpFirebase\Entities\Bridge` | Gone. Property mapping is internal to the hydrator. |
| `PhpFirebase\Entities\Call` | Gone. Declare typed properties instead of magic accessors. |
| `guid()` | Gone. Ids come from the backend, which orders them chronologically. |
| The `extra/` autoload path | Everything lives under `src/` now. |

### What changed

**Namespaces.** `PhpFirebase\Entities\Entity` is now `PhpFirebase\Entity`, and
`PhpFirebase\Entities\Repository\Repository` is now `PhpFirebase\Repository`.

**Constructing a repository.** A repository no longer builds its own client from
a URL and a token. It takes a connection, and names its collection and entity
class through methods:

```php
// 0.x
class UserRepository extends Repository
{
    public function __construct()
    {
        $this->class = User::class;
        parent::__construct('https://hey-123.firebaseio.com', 'secret-token', '/users');
    }
}

// 1.0
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

**Entities do not take an array constructor.** Use `User::fromArray($data)`.

If you declare a constructor of your own, every persisted property has to be
promoted into it - see the note on partial constructors below. 0.x entities
declared no constructor, so most carry over untouched.

**The id is no longer stored inside the record.** It is the key. Existing data
that carries an `id` field still reads correctly; the key wins when they disagree,
and the field is dropped the next time the record is written.

**Method names.**

| 0.x | 1.0 |
| --- | --- |
| `store($entity)` | `save($entity)`, or `saveMany($entities)` for a list |
| `find($id)` | `find($id)` returns `null` when missing; `get($id)` throws |
| `fetch($criteria)` | `query()` with `where()` / `orderBy()` / `limit()`, then `fetch()` |
| `get()` | Gone - repositories no longer hold the last result. Use the value `fetch()` returns. |
| `top($n)` / `tail($n)` | `limit($n)`, with `orderBy(..., Direction::Descending)` for the tail |
| `orderBy($field)` | `orderBy($field, Direction::Ascending)` |
| `deleteAll()` | `deleteAll()` (unchanged) |

**Queries are immutable.** In 0.x, `query()`, `top()` and `orderBy()` mutated the
repository, so constraints leaked into the next call. Each call now returns a new
query and the repository holds no state.

**You must now require the Realtime Database client yourself.** This is a step
everyone upgrading has to take: you came from 0.x, so the Realtime Database is
what you are using.

0.x reached it through its own bundled HTTP client. 1.0 reaches it through
`kreait/firebase-php`, which is not installed automatically - Firestore users do
not need it, so it is not a hard dependency of the package. For you it is
required:

```bash
composer require kreait/firebase-php
```

**There is more than there was.** 0.x could read and write records and run a
single-field query. 1.0 adds batched writes, optimistic transactions
(`modify()` / `transaction()`), server-side value sentinels, subcollections, and
Firestore as a second backend. None of it has a 0.x equivalent to migrate from.

**Errors are typed.** Everything this library throws implements
`PhpFirebase\Exception\PhpFirebaseException`: `EntityNotFound`, `InvalidEntity`,
`UnsupportedQuery`, `TransactionConflict`, and `BackendError` for a request the
backend refused. On the Realtime Database, transport failures still surface as
`kreait/firebase-php`'s own exceptions.

**Entities may not declare a partial constructor.** A constructor covering only
some persisted properties is rejected, because the rest would be silently
skipped on read. Either declare no constructor, or promote every persisted
property into it. 0.x entities declared no constructor, so most will be fine as
they are.
