<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Firestore;

use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\Operator;
use PhpFirebase\EntityTransaction;
use PhpFirebase\Exception\BackendError;
use PhpFirebase\Exception\TransactionConflict;
use PhpFirebase\Tests\FakeHttpClient;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins down exactly what the connection puts on the Firestore REST API.
 */
#[CoversClass(FirestoreConnection::class)]
#[CoversClass(\PhpFirebase\Database\Firestore\QueryTranslator::class)]
final class FirestoreConnectionTest extends TestCase
{
    private const string DOCUMENTS = '/v1/projects/test-project/databases/(default)/documents';

    private FakeHttpClient $http;

    private FirestoreConnection $connection;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->connection = new FirestoreConnection(
            $this->http->client(),
            $this->http->factory(),
            $this->http->factory(),
            'test-project',
        );
        $this->users = new UserRepository($this->connection);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function document(string $id, array $fields): array
    {
        return [
            'name' => 'projects/test-project/databases/(default)/documents/users/'.$id,
            'fields' => $fields,
        ];
    }

    #[Test]
    public function reading_a_document_is_a_get_on_its_path(): void
    {
        $this->http->willRespondWith($this->document('user-1', [
            'firstName' => ['stringValue' => 'Ada'],
            'age' => ['integerValue' => '36'],
        ]));

        $user = $this->users->find('user-1');

        $this->assertSame('GET', $this->http->lastRequest()->getMethod());
        $this->assertSame(self::DOCUMENTS.'/users/user-1', $this->http->lastPath());
        $this->assertNotNull($user);
        $this->assertSame('Ada', $user->firstName);
        $this->assertSame(36, $user->age);
        $this->assertSame('user-1', $user->id());
    }

    #[Test]
    public function a_missing_document_reads_as_null(): void
    {
        $this->http->willRespondWith(['error' => ['status' => 'NOT_FOUND']], 404);

        $this->assertNull($this->users->find('nope'));
    }

    #[Test]
    public function a_refused_request_carries_the_status_and_what_the_api_said(): void
    {
        $this->http->willRespondWith([
            'error' => ['message' => 'The query requires an index. You can create it here: https://example.test/idx'],
        ], 400);

        try {
            $this->users->all();
            $this->fail('Expected the request to be reported as failed.');
        } catch (BackendError $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('requires an index', $e->reason);
            $this->assertStringContainsString('400', $e->getMessage());
        }
    }

    #[Test]
    public function it_reads_the_error_out_of_a_streaming_response(): void
    {
        // runQuery streams, so Firestore reports a failure as an array holding
        // the error rather than as the error itself. Missing this shape loses
        // the message, which for a missing index carries the link that fixes it.
        $this->http->willRespondWith([[
            'error' => [
                'code' => 400,
                'message' => 'The query requires an index. You can create it here: https://example.test/create',
                'status' => 'FAILED_PRECONDITION',
            ],
        ]], 400);

        try {
            $this->users->all();
            $this->fail('Expected the request to be reported as failed.');
        } catch (BackendError $e) {
            $this->assertStringContainsString('requires an index', $e->reason);
            $this->assertStringContainsString('https://example.test/create', $e->getMessage());
            $this->assertSame('FAILED_PRECONDITION', $e->googleStatus);
        }
    }

    #[Test]
    public function it_falls_back_to_the_status_line_when_there_is_no_error_body(): void
    {
        $this->http->willRespondWith('', 503);

        try {
            $this->users->find('user-1');
            $this->fail('Expected the request to be reported as failed.');
        } catch (BackendError $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('', $e->googleStatus);
        }
    }

    #[Test]
    public function a_permission_failure_is_not_mistaken_for_a_missing_document(): void
    {
        $this->http->willRespondWith(['error' => ['message' => 'Missing or insufficient permissions.']], 403);

        $this->expectException(BackendError::class);

        $this->users->find('user-1');
    }

    #[Test]
    public function creating_a_document_posts_to_the_collection(): void
    {
        $this->http->willRespondWith($this->document('generated-id', []));

        $saved = $this->users->save(User::named('Ada', 'Lovelace'));

        $this->assertSame('POST', $this->http->lastRequest()->getMethod());
        $this->assertSame(self::DOCUMENTS.'/users', $this->http->lastPath());
        $this->assertSame('generated-id', $saved->id());
        $this->assertSame(['stringValue' => 'Ada'], $this->http->lastBody()['fields']['firstName']);
    }

    #[Test]
    public function saving_an_identified_entity_patches_its_path(): void
    {
        $this->http->willRespondWith($this->document('user-1', []));

        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->assertSame('PATCH', $this->http->lastRequest()->getMethod());
        $this->assertSame(self::DOCUMENTS.'/users/user-1', $this->http->lastPath());
        // No update mask, so this replaces the document rather than merging.
        $this->assertSame('', $this->http->lastQueryString());
    }

    #[Test]
    public function the_written_document_never_contains_the_id(): void
    {
        $this->http->willRespondWith($this->document('user-1', []));

        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->assertArrayNotHasKey('id', $this->http->lastBody()['fields']);
    }

    #[Test]
    public function merging_sends_an_update_mask_naming_only_those_fields(): void
    {
        $this->http->willRespondWith($this->document('user-1', []));

        $this->users->update('user-1', ['lastName' => 'King', 'age' => 37]);

        $this->assertSame('PATCH', $this->http->lastRequest()->getMethod());
        $this->assertSame(
            'updateMask.fieldPaths=lastName&updateMask.fieldPaths=age',
            urldecode($this->http->lastQueryString()),
        );
    }

    #[Test]
    public function deleting_a_document_is_a_delete_on_its_path(): void
    {
        $this->http->willRespondWith([]);

        $this->users->delete('user-1');

        $this->assertSame('DELETE', $this->http->lastRequest()->getMethod());
        $this->assertSame(self::DOCUMENTS.'/users/user-1', $this->http->lastPath());
    }

    #[Test]
    public function querying_posts_a_structured_query(): void
    {
        $this->http->willRespondWith([
            ['document' => $this->document('user-1', ['firstName' => ['stringValue' => 'Ada']])],
            ['document' => $this->document('user-2', ['firstName' => ['stringValue' => 'Grace']])],
        ]);

        $found = $this->users->query()->whereEquals('role', 'admin')->fetch();

        $this->assertSame('POST', $this->http->lastRequest()->getMethod());
        $this->assertSame(self::DOCUMENTS.':runQuery', $this->http->lastPath());

        $query = $this->http->lastBody()['structuredQuery'];
        $this->assertSame([['collectionId' => 'users']], $query['from']);
        $this->assertSame([
            'field' => ['fieldPath' => 'role'],
            'op' => 'EQUAL',
            'value' => ['stringValue' => 'admin'],
        ], $query['where']['fieldFilter']);

        $this->assertSame(['user-1', 'user-2'], array_keys($found));
        $this->assertSame('Grace', $found['user-2']->firstName);
    }

    #[Test]
    public function two_filters_become_a_composite_and(): void
    {
        // The query Firestore runs happily and the Realtime Database refuses.
        $this->http->willRespondWith([]);

        $this->users->query()
            ->whereEquals('role', 'admin')
            ->where('age', Operator::GreaterThan, 30)
            ->fetch();

        $where = $this->http->lastBody()['structuredQuery']['where'];

        $this->assertSame('AND', $where['compositeFilter']['op']);
        $this->assertCount(2, $where['compositeFilter']['filters']);
    }

    #[Test]
    public function ordering_and_limits_are_sent_as_given(): void
    {
        $this->http->willRespondWith([]);

        $this->users->query()->orderBy('age', Direction::Descending)->limit(5)->fetch();

        $query = $this->http->lastBody()['structuredQuery'];

        $this->assertSame([['field' => ['fieldPath' => 'age'], 'direction' => 'DESCENDING']], $query['orderBy']);
        $this->assertSame(5, $query['limit']);
    }

    #[Test]
    public function ordering_by_key_uses_the_document_name_field(): void
    {
        $this->http->willRespondWith([]);

        $this->users->query()->orderByKey()->fetch();

        $this->assertSame(
            '__name__',
            $this->http->lastBody()['structuredQuery']['orderBy'][0]['field']['fieldPath'],
        );
    }

    #[Test]
    public function results_without_a_document_are_skipped(): void
    {
        // Firestore reports "nothing matched" with a read timestamp only.
        $this->http->willRespondWith([['readTime' => '2024-03-01T10:00:00Z']]);

        $this->assertSame([], $this->users->all());
    }

    #[Test]
    public function a_transaction_begins_reads_and_commits_together(): void
    {
        $this->http
            ->willRespondWith(['transaction' => 'TX1'])
            ->willRespondWith($this->document('user-1', ['firstName' => ['stringValue' => 'Ada']]))
            ->willRespondWith(['writeResults' => []]);

        $this->users->transaction(static function (EntityTransaction $users): void {
            $user = $users->get('user-1');
            $user->firstName = 'Ada Byron';
            $users->save($user);
        });

        $requests = array_map(
            fn (int $i): string => $this->http->requestAt($i)->getUri()->getPath(),
            [0, 1, 2],
        );

        $this->assertSame([
            self::DOCUMENTS.':beginTransaction',
            self::DOCUMENTS.'/users/user-1',
            self::DOCUMENTS.':commit',
        ], $requests);

        // The read is tagged with the transaction, and the commit carries it.
        $this->assertSame('transaction=TX1', $this->http->requestAt(1)->getUri()->getQuery());

        $body = $this->http->lastBody();
        $this->assertSame('TX1', $body['transaction']);
        $this->assertSame(['stringValue' => 'Ada Byron'], $body['writes'][0]['update']['fields']['firstName']);
    }

    #[Test]
    public function a_conflicting_commit_is_retried_with_fresh_data(): void
    {
        $this->http
            // First attempt: someone else commits first, so ours is aborted.
            ->willRespondWith(['transaction' => 'TX1'])
            ->willRespondWith($this->document('user-1', ['age' => ['integerValue' => '36']]))
            ->willRespondWith(['error' => ['message' => 'ABORTED']], 409)
            // Second attempt sees the newer value and succeeds.
            ->willRespondWith(['transaction' => 'TX2'])
            ->willRespondWith($this->document('user-1', ['age' => ['integerValue' => '40']]))
            ->willRespondWith(['writeResults' => []]);

        $attempts = 0;

        $result = $this->users->modify('user-1', static function (User $user) use (&$attempts): User {
            ++$attempts;
            ++$user->age;

            return $user;
        });

        $this->assertSame(2, $attempts, 'The work should have been repeated after the conflict.');
        $this->assertSame(41, $result->age, 'The retry should have started from the newer value.');
        $this->assertSame('TX2', $this->http->lastBody()['transaction']);
    }

    #[Test]
    public function a_transaction_that_keeps_conflicting_eventually_gives_up(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->http
                ->willRespondWith(['transaction' => 'TX'.$i])
                ->willRespondWith($this->document('user-1', []))
                ->willRespondWith(['error' => ['message' => 'ABORTED']], 409);
        }

        $this->expectException(TransactionConflict::class);
        $this->expectExceptionMessage('after 3 attempts');

        $this->users->modify('user-1', static fn (User $user): User => $user, attempts: 3);
    }

    #[Test]
    public function a_failure_inside_the_work_rolls_the_transaction_back(): void
    {
        $this->http
            ->willRespondWith(['transaction' => 'TX1'])
            ->willRespondWith($this->document('user-1', []))
            ->willRespondWith([]);

        $caught = null;

        try {
            $this->users->transaction(static function (EntityTransaction $users): void {
                $users->get('user-1');

                throw new \DomainException('nope');
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\DomainException::class, $caught);
        $this->assertSame(self::DOCUMENTS.':rollback', $this->http->lastPath());
        $this->assertSame('TX1', $this->http->lastBody()['transaction']);
    }

    #[Test]
    public function a_subcollection_document_is_addressed_by_its_full_path(): void
    {
        $this->http->willRespondWith([
            'name' => 'projects/test-project/databases/(default)/documents/users/ada/orders/order-1',
            'fields' => ['reference' => ['stringValue' => 'ORD-1']],
        ]);

        $record = $this->connection->read('users/ada/orders', 'order-1');

        $this->assertSame(self::DOCUMENTS.'/users/ada/orders/order-1', $this->http->lastPath());
        $this->assertSame(['reference' => 'ORD-1'], $record);
    }

    #[Test]
    public function a_subcollection_is_queried_against_its_parent_document(): void
    {
        // Firestore rejects a collectionId containing a slash: the parent
        // belongs in the request path and only the leaf names the collection.
        $this->http->willRespondWith([]);

        $this->connection->readAll('users/ada/orders', Criteria::none());

        $this->assertSame(self::DOCUMENTS.'/users/ada:runQuery', $this->http->lastPath());
        $this->assertSame(
            [['collectionId' => 'orders']],
            $this->http->lastBody()['structuredQuery']['from'],
        );
    }

    #[Test]
    public function counting_a_subcollection_is_addressed_the_same_way(): void
    {
        $this->http->willRespondWith([
            ['result' => ['aggregateFields' => ['count' => ['integerValue' => '2']]]],
        ]);

        $count = $this->connection->count('users/ada/orders', Criteria::none());

        $this->assertSame(self::DOCUMENTS.'/users/ada:runAggregationQuery', $this->http->lastPath());
        $this->assertSame(2, $count);
    }

    #[Test]
    public function counting_uses_an_aggregation_query(): void
    {
        $this->http->willRespondWith([
            ['result' => ['aggregateFields' => ['count' => ['integerValue' => '7']]]],
        ]);

        $count = $this->users->count();

        $this->assertSame(self::DOCUMENTS.':runAggregationQuery', $this->http->lastPath());
        $this->assertSame(
            [['count' => [], 'alias' => 'count']],
            $this->http->lastBody()['structuredAggregationQuery']['aggregations'],
        );
        $this->assertSame(7, $count);
    }

    #[Test]
    public function truncating_reads_the_collection_then_deletes_it_in_one_commit(): void
    {
        $this->http
            ->willRespondWith([
                ['document' => $this->document('user-1', [])],
                ['document' => $this->document('user-2', [])],
            ])
            ->willRespondWith([]);

        $this->users->deleteAll();

        // One read plus one commit, however many documents there were.
        $this->assertSame(2, $this->http->requestCount());
        $this->assertSame(self::DOCUMENTS.':commit', $this->http->lastPath());
        $this->assertSame([
            ['delete' => 'projects/test-project/databases/(default)/documents/users/user-1'],
            ['delete' => 'projects/test-project/databases/(default)/documents/users/user-2'],
        ], $this->http->lastBody()['writes']);
    }

    #[Test]
    public function truncating_an_empty_collection_commits_nothing(): void
    {
        $this->http->willRespondWith([]);

        $this->users->deleteAll();

        $this->assertSame(1, $this->http->requestCount());
    }

    #[Test]
    public function saving_identified_entities_takes_a_single_commit(): void
    {
        $this->http->willRespondWith([]);

        $this->users->saveMany([
            User::named('Ada', 'Lovelace')->withId('user-1'),
            User::named('Grace', 'Hopper')->withId('user-2'),
        ]);

        $this->assertSame(1, $this->http->requestCount());
        $this->assertSame(self::DOCUMENTS.':commit', $this->http->lastPath());

        $writes = $this->http->lastBody()['writes'];
        $this->assertCount(2, $writes);
        $this->assertSame(
            'projects/test-project/databases/(default)/documents/users/user-1',
            $writes[0]['update']['name'],
        );
        $this->assertSame(['stringValue' => 'Grace'], $writes[1]['update']['fields']['firstName']);
    }

    #[Test]
    public function an_id_with_awkward_characters_is_escaped_in_the_path(): void
    {
        $this->http->willRespondWith($this->document('a b', []));

        $this->connection->read('users', 'a b');

        $this->assertSame(self::DOCUMENTS.'/users/a%20b', $this->http->lastRequest()->getUri()->getPath());
    }
}
