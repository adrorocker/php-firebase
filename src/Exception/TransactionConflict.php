<?php

declare(strict_types=1);

namespace PhpFirebase\Exception;

use RuntimeException;
use Throwable;

/**
 * A transaction kept losing to concurrent writers and ran out of attempts.
 *
 * Both backends detect conflicts optimistically: the work is done against a
 * snapshot, and the commit is refused if anything the transaction read has
 * changed underneath it. Retrying is normally the right response, and this is
 * thrown only once retrying has stopped being worth it.
 */
final class TransactionConflict extends RuntimeException implements PhpFirebaseException
{
    public function __construct(
        public readonly int $attempts,
        ?Throwable $previous = null,
    ) {
        $because = $previous?->getMessage();

        parent::__construct(
            sprintf(
                'The transaction still conflicted with another writer after %d %s.%s',
                $attempts,
                $attempts === 1 ? 'attempt' : 'attempts',
                $because === null || trim($because) === '' ? '' : ' The last attempt failed with: '.$because,
            ),
            previous: $previous,
        );
    }
}
