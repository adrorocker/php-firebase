<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Message\RequestInterface;

/**
 * A PSR-18 client with a queued, recorded transport.
 *
 * The Firestore connection speaks plain HTTP through PSR-18, so faking the
 * transport is enough to assert exactly what it puts on the wire. Guzzle's
 * client implements PSR-18, which is why it stands in for one here.
 */
final class FakeHttpClient
{
    private readonly MockHandler $handler;

    private readonly Client $client;

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function __construct()
    {
        $this->handler = new MockHandler();

        $stack = HandlerStack::create($this->handler);
        $stack->push($this->recordRequests());

        // http_errors off, because a PSR-18 client returns 4xx and 5xx rather
        // than throwing, and the connection under test relies on that.
        $this->client = new Client(['handler' => $stack, 'http_errors' => false]);
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function factory(): HttpFactory
    {
        return new HttpFactory();
    }

    public function willRespondWith(mixed $body, int $status = 200): self
    {
        $this->handler->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));

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

    public function lastPath(): string
    {
        return $this->lastRequest()->getUri()->getPath();
    }

    public function lastQueryString(): string
    {
        return $this->lastRequest()->getUri()->getQuery();
    }

    /**
     * @return array<string, mixed>
     */
    public function lastBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
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
