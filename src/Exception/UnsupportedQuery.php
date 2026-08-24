<?php

declare(strict_types=1);

namespace PhpFirebase\Exception;

use RuntimeException;

/**
 * Thrown when a query cannot be expressed against the backend in use.
 *
 * The Realtime Database in particular can filter and order on a single field
 * only, so queries that are ordinary in Firestore have no equivalent there.
 */
final class UnsupportedQuery extends RuntimeException implements PhpFirebaseException
{
    /**
     * @param list<string> $keys
     */
    public static function unknownExtras(string $backend, array $keys): self
    {
        return self::because($backend, sprintf(
            'it does not understand the %s %s. A connection must refuse an extra it cannot honour, '
            .'rather than answer a different query than the one asked for.',
            implode(', ', array_map(static fn (string $k): string => '"'.$k.'"', $keys)),
            count($keys) === 1 ? 'extra' : 'extras',
        ));
    }

    public static function because(string $backend, string $reason): self
    {
        return new self(sprintf('%s cannot run this query: %s', $backend, $reason));
    }
}
