<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use PhpFirebase\Database\Connection;
use PhpFirebase\Database\Criteria;
use PhpFirebase\Exception\BackendError;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Exception\TransactionConflict;
use PhpFirebase\Exception\UnsupportedQuery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Cloud Firestore, over its REST API.
 *
 * Google's own `google/cloud-firestore` requires `ext-grpc`, a compiled
 * extension that a library cannot reasonably force on its users. Firestore also
 * exposes a plain HTTP API, which is what this connection speaks, so it runs
 * anywhere PHP and Guzzle do.
 *
 * Build one with {@see FirestoreConnectionFactory}, or hand it any PSR-18
 * client that already attaches credentials.
 */
class FirestoreConnection implements Connection
{
    public const string BASE_URI = 'https://firestore.googleapis.com/v1/';

    protected readonly string $documentsPath;

    public function __construct(
        protected readonly ClientInterface $client,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        string $projectId,
        string $databaseId = '(default)',
        protected readonly ValueEncoder $encoder = new ValueEncoder(),
        protected readonly QueryTranslator $translator = new QueryTranslator(),
    ) {
        $this->documentsPath = sprintf('projects/%s/databases/%s/documents', $projectId, $databaseId);
    }

    public function read(string $collection, string $id): ?array
    {
        $path = $this->documentPath($collection, $id);
        $response = $this->send('GET', $path);

        // A document that is not there is an answer, not a failure.
        if ($response->getStatusCode() === 404) {
            return null;
        }

        return $this->fieldsOf($this->decode($response, 'GET', $path));
    }

    public function readAll(string $collection, Criteria $criteria): array
    {
        $this->guardExtras($criteria);

        $response = $this->request('POST', $this->queryPath($collection).':runQuery', [
            'structuredQuery' => $this->translator->translate($this->collectionId($collection), $criteria),
        ]);

        $records = [];

        foreach ($response as $result) {
            // A result carries either a document or only a read timestamp,
            // which is how Firestore reports "nothing matched".
            if (!is_array($result) || !isset($result['document']) || !is_array($result['document'])) {
                continue;
            }

            $document = $result['document'];
            $id = $this->idOf($document);

            if ($id !== null) {
                $records[$id] = $this->fieldsOf($document);
            }
        }

        return $records;
    }

    public function write(string $collection, string $id, array $data): void
    {
        $write = FirestoreWrite::encode($data, $this->encoder);

        // A server-side transform is only expressible in a commit, so a write
        // carrying one takes that route instead of the plain document endpoint.
        if ($write->needsCommit()) {
            $this->commit([$this->documentWrite($collection, $id, $write)]);

            return;
        }

        // A patch with no update mask replaces the document, creating it if it
        // is not there yet, so removed fields need nothing more than absence.
        $this->request('PATCH', $this->documentPath($collection, $id), ['fields' => $write->jsonFields()]);
    }

    public function writeMany(string $collection, array $records): void
    {
        if ($records === []) {
            return;
        }

        $writes = [];

        foreach ($records as $id => $data) {
            $writes[] = $this->documentWrite($collection, (string) $id, FirestoreWrite::encode($data, $this->encoder));
        }

        $this->commit($writes);
    }

    public function transaction(callable $work, int $attempts = 5): mixed
    {
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $id = $this->beginTransaction();
            $transaction = new FirestoreTransaction($this, $id);

            try {
                $result = $work($transaction);
            } catch (\Throwable $e) {
                $this->rollback($id);

                throw $e;
            }

            $response = $this->send('POST', $this->documentsPath.':commit', [
                'writes' => $transaction->writes(),
                'transaction' => $id,
            ]);

            // ABORTED means something this transaction read has changed, which
            // is the one failure worth simply trying again.
            if ($response->getStatusCode() === 409) {
                continue;
            }

            $this->decode($response, 'POST', $this->documentsPath.':commit');

            return $result;
        }

        throw new TransactionConflict($attempts);
    }

    /**
     * @internal for {@see FirestoreTransaction}
     *
     * @return array<string, mixed>|null
     */
    public function readInTransaction(string $collection, string $id, string $transaction): ?array
    {
        $path = $this->documentPath($collection, $id).'?transaction='.urlencode($transaction);
        $response = $this->send('GET', $path);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        return $this->fieldsOf($this->decode($response, 'GET', $path));
    }

    /**
     * The one place a Firestore write body is built. It takes a
     * {@see FirestoreWrite}, which cannot exist without having been encoded,
     * so no write path can skip sentinel handling.
     *
     * @return array<string, mixed>
     */
    public function documentWrite(string $collection, string $id, FirestoreWrite $write, bool $merge = false): array
    {
        $body = ['update' => [
            'name' => $this->documentName($collection, $id),
            'fields' => $write->jsonFields(),
        ]];

        if ($write->transforms !== []) {
            $body['updateTransforms'] = $write->transforms;
        }

        if ($merge) {
            // Every field the caller mentioned, including the removed ones, so
            // that anything else on the document survives.
            $body['updateMask'] = ['fieldPaths' => $write->touched];
        }

        return $body;
    }

    /**
     * @internal for {@see FirestoreTransaction}
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateWrite(string $collection, string $id, array $data): array
    {
        return $this->documentWrite($collection, $id, FirestoreWrite::encode($data, $this->encoder));
    }

    /**
     * @internal for {@see FirestoreTransaction}
     *
     * @return array<string, mixed>
     */
    public function deleteWrite(string $collection, string $id): array
    {
        return ['delete' => $this->documentName($collection, $id)];
    }

    public function create(string $collection, array $data): string
    {
        $write = FirestoreWrite::encode($data, $this->encoder);

        // The endpoint that allocates an id cannot apply a field transform, so
        // a record carrying one gets an id here instead and is written through
        // a commit. Firestore's own client SDKs generate ids the same way.
        if ($write->needsCommit()) {
            $id = self::generateId();

            $this->commit([$this->documentWrite($collection, $id, $write)]);

            return $id;
        }

        $created = $this->request('POST', $this->documentsPath.'/'.$collection, [
            'fields' => $write->jsonFields(),
        ]);

        return $this->idOf($created)
            ?? throw new InvalidEntity('Firestore did not return a name for the created document.');
    }

    /**
     * A document id shaped like the ones Firestore allocates: twenty
     * characters of upper case, lower case and digits.
     */
    private static function generateId(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';

        for ($i = 0; $i < 20; ++$i) {
            $id .= $alphabet[random_int(0, 61)];
        }

        return $id;
    }

    public function merge(string $collection, string $id, array $data): void
    {
        $write = FirestoreWrite::encode($data, $this->encoder);

        if ($write->needsCommit()) {
            $this->commit([$this->documentWrite($collection, $id, $write, merge: true)]);

            return;
        }

        // The update mask is what makes this a merge rather than a replace;
        // without it the fields left out would be deleted. A field named in the
        // mask but absent from the values is removed, which is how a removal
        // sentinel takes effect.
        $mask = implode('&', array_map(
            static fn (string $field): string => 'updateMask.fieldPaths='.urlencode($field),
            $write->touched,
        ));

        $path = $this->documentPath($collection, $id).($mask === '' ? '' : '?'.$mask);

        $this->request('PATCH', $path, ['fields' => $write->jsonFields()]);
    }

    public function delete(string $collection, string $id): void
    {
        $this->request('DELETE', $this->documentPath($collection, $id));
    }

    public function truncate(string $collection): void
    {
        // Firestore has no truncate: a collection is only the documents in it.
        // So this reads the ids and deletes them in one commit. A collection
        // too large to read in one response is better emptied from a batched
        // job than from a single request.
        $ids = array_keys($this->readAll($collection, Criteria::none()));

        if ($ids === []) {
            return;
        }

        $this->commit(array_map(
            fn (string $id): array => ['delete' => $this->documentName($collection, $id)],
            $ids,
        ));
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $this->guardExtras($criteria);

        $response = $this->request('POST', $this->queryPath($collection).':runAggregationQuery', [
            'structuredAggregationQuery' => [
                'structuredQuery' => $this->translator->translate($this->collectionId($collection), $criteria),
                'aggregations' => [['count' => (object) [], 'alias' => 'count']],
            ],
        ]);

        foreach ($response as $result) {
            $value = $result['result']['aggregateFields']['count'] ?? null;

            if (is_array($value)) {
                $count = $this->encoder->decode($value);

                if (is_int($count)) {
                    return $count;
                }
            }
        }

        return 0;
    }

    /**
     * The extra keys this connection knows how to honour. Override in a
     * subclass that adds one - a cursor, for instance - and read it back off
     * the criteria in an overridden {@see QueryTranslator}.
     *
     * @return list<string>
     */
    protected function supportedExtras(): array
    {
        return [];
    }

    private function guardExtras(Criteria $criteria): void
    {
        $unsupported = $criteria->unsupportedExtras($this->supportedExtras());

        if ($unsupported !== []) {
            throw UnsupportedQuery::unknownExtras('Firestore', $unsupported);
        }
    }

    private function beginTransaction(): string
    {
        $response = $this->request('POST', $this->documentsPath.':beginTransaction', [
            'options' => ['readWrite' => (object) []],
        ]);

        $id = $response['transaction'] ?? null;

        if (!is_string($id) || $id === '') {
            throw BackendError::unexpectedResponse('beginTransaction returned no transaction id.');
        }

        return $id;
    }

    /**
     * Abandons a transaction so the server stops holding its locks. A failure
     * here is not worth reporting: the transaction is already being abandoned,
     * and Firestore expires it on its own.
     */
    private function rollback(string $id): void
    {
        try {
            $this->send('POST', $this->documentsPath.':rollback', ['transaction' => $id]);
        } catch (\Throwable) {
        }
    }

    /**
     * Writes several documents in one request. Firestore commits a batch
     * atomically, so either all of them land or none do.
     *
     * @param list<array<string, mixed>> $writes
     */
    private function commit(array $writes): void
    {
        $this->request('POST', $this->documentsPath.':commit', ['writes' => $writes]);
    }

    /**
     * Where a query is addressed.
     *
     * A subcollection like "users/ada/orders" is queried against its parent
     * document, with only the last segment naming the collection. Firestore
     * rejects a collectionId containing a slash, so the two have to be split.
     */
    private function queryPath(string $collection): string
    {
        $position = strrpos($collection, '/');

        return $position === false
            ? $this->documentsPath
            : $this->documentsPath.'/'.substr($collection, 0, $position);
    }

    /**
     * The last segment of a collection path, which is the collection's own id.
     */
    private function collectionId(string $collection): string
    {
        $position = strrpos($collection, '/');

        return $position === false ? $collection : substr($collection, $position + 1);
    }

    private function documentPath(string $collection, string $id): string
    {
        return sprintf('%s/%s/%s', $this->documentsPath, $collection, rawurlencode($id));
    }

    /**
     * The resource name of a document, as it appears inside a request body
     * rather than in a URL, so it is not escaped.
     */
    private function documentName(string $collection, string $id): string
    {
        return sprintf('%s/%s/%s', $this->documentsPath, $collection, $id);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->decode($this->send($method, $path, $body), $method, $path);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, self::BASE_URI.$path)
            ->withHeader('Accept', 'application/json');

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->client->sendRequest($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(ResponseInterface $response, string $method, string $path): array
    {
        if ($response->getStatusCode() >= 400) {
            throw BackendError::fromResponse($method, self::BASE_URI.$path, $response);
        }

        $body = (string) $response->getBody();

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function fieldsOf(array $document): array
    {
        $fields = $document['fields'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        /** @var array<string, mixed> $fields */
        return $this->encoder->decodeFields($fields);
    }

    /**
     * The document id is the last segment of its resource name.
     *
     * @param array<array-key, mixed> $document
     */
    private function idOf(array $document): ?string
    {
        $name = $document['name'] ?? null;

        if (!is_string($name) || $name === '') {
            return null;
        }

        $position = strrpos($name, '/');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
