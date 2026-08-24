<?php

declare(strict_types=1);

namespace PhpFirebase\Exception;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A backend refused a request.
 *
 * Carries the HTTP status and whatever the API said, because Firebase error
 * bodies are usually specific and worth reading: a missing index, for example,
 * comes back with a link that creates it.
 */
final class BackendError extends RuntimeException implements PhpFirebaseException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
        /**
         * Google's own status name, such as FAILED_PRECONDITION or
         * PERMISSION_DENIED. Empty when the body did not carry one.
         */
        public readonly string $googleStatus = '',
    ) {
        parent::__construct($message);
    }

    /**
     * The request succeeded but the body was not what the API documents.
     */
    public static function unexpectedResponse(string $what): self
    {
        return new self(sprintf('Unexpected response from the backend: %s', $what), 0, $what);
    }

    public static function fromResponse(string $method, string $url, ResponseInterface $response): self
    {
        $status = $response->getStatusCode();
        $error = self::errorFrom((string) $response->getBody());

        $reason = $error['message'] ?? $response->getReasonPhrase();
        $googleStatus = $error['status'] ?? '';

        return new self(
            sprintf('%s %s failed with %d: %s', $method, $url, $status, $reason),
            $status,
            $reason,
            $googleStatus,
        );
    }

    /**
     * Digs the useful part out of a Google error body.
     *
     * Most endpoints answer with `{"error": {...}}`. The streaming ones -
     * `:runQuery` and `:runAggregationQuery` - answer with a JSON *array* of
     * results, and report a failure as an array holding that same object.
     * Missing the second shape loses the most valuable message these APIs
     * produce: a missing index comes back with a link that creates it.
     *
     * @return array{message?: string, status?: string}
     */
    private static function errorFrom(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [];
        }

        // Unwrap the streaming form, which puts the error in the first entry.
        if (!array_key_exists('error', $decoded)) {
            $first = $decoded[0] ?? null;
            $decoded = is_array($first) ? $first : [];
        }

        $error = $decoded['error'] ?? null;

        if (is_string($error)) {
            return ['message' => $error];
        }

        if (!is_array($error)) {
            return [];
        }

        $found = [];

        if (isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            $found['message'] = $error['message'];
        }

        if (isset($error['status']) && is_string($error['status'])) {
            $found['status'] = $error['status'];
        }

        return $found;
    }
}
