<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Kreait\Firebase\Contract;
use Kreait\Firebase\Database;
use Kreait\Firebase\Database\ApiClient;
use Kreait\Firebase\Database\UrlBuilder;
use Kreait\Firebase\Exception\DatabaseApiExceptionConverter;
use Kreait\Firebase\Http\ErrorResponseParser;
use Kreait\Firebase\Http\Middleware as FirebaseMiddleware;
use LogicException;
use Psr\Http\Message\RequestInterface;

/**
 * A real kreait Database wired to a mocked HTTP transport.
 *
 * Reference and Query are final readonly classes, so they cannot be doubled.
 * Faking the transport underneath them instead means the tests exercise the
 * genuine kreait code path, including the URLs and payloads it produces.
 */
final class FakeDatabase
{
    public const string URI = 'https://php-firebase-tests.firebaseio.com';

    private readonly MockHandler $handler;

    private readonly Contract\Database $database;

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function __construct()
    {
        $this->handler = new MockHandler();

        $stack = HandlerStack::create($this->handler);
        // The real Factory installs this, and it is what appends the ".json"
        // suffix every Realtime Database URL needs.
        $stack->push(FirebaseMiddleware::ensureJsonSuffix());
        $stack->push($this->recordRequests());

        $apiClient = new ApiClient(
            new Client(['handler' => $stack]),
            UrlBuilder::create(self::URI),
            new DatabaseApiExceptionConverter(new ErrorResponseParser()),
        );

        $this->database = new Database(new Uri(self::URI), $apiClient);
    }

    public function database(): Contract\Database
    {
        return $this->database;
    }

    /**
     * Queues the next response body, which is sent back as JSON.
     */
    public function willRespondWith(mixed $body, int $status = 200): self
    {
        $this->handler->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));

        return $this;
    }

    /**
     * A read inside a transaction, which the database answers with a version
     * tag that the matching write is then checked against.
     */
    public function willRespondWithETag(mixed $body, string $etag): self
    {
        $this->handler->append(new Response(
            200,
            ['Content-Type' => 'application/json', 'ETag' => $etag],
            json_encode($body, JSON_THROW_ON_ERROR),
        ));

        return $this;
    }

    public function requestAt(int $index): RequestInterface
    {
        return $this->requests[$index]
            ?? throw new LogicException(sprintf('No request was made at index %d.', $index));
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1]
            ?? throw new LogicException('No request was made.');
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The query parameters of the last request.
     *
     * @return array<string, string>
     */
    public function lastQuery(): array
    {
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function lastBody(): mixed
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    /**
     * Guzzle ships a history middleware, but it fills a container passed by
     * reference; recording straight into the property keeps the types honest.
     *
     * @return Closure(callable): Closure
     */
    private function recordRequests(): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler) {
            $this->requests[] = $request;

            return $handler($request, $options);
        };
    }
}
