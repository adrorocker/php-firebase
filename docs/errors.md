# When something goes wrong

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
