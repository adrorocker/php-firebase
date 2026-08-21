<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;
use PhpFirebase\Database\RealtimeDatabaseConnection;
use PhpFirebase\Database\Sentinel;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Exception\TransactionConflict;
use PhpFirebase\Exception\UnsupportedQuery;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins down what actually goes over the wire to the Realtime Database, and
 * which queries the adapter refuses rather than answering wrongly.
 */
#[CoversClass(RealtimeDatabaseConnection::class)]
final class RealtimeDatabaseConnectionTest extends TestCase
{
    private FakeDatabase $firebase;

    private RealtimeDatabaseConnection $connection;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->firebase = new FakeDatabase();
        $this->connection = new RealtimeDatabaseConnection($this->firebase->database());
        $this->users = new UserRepository($this->connection);
    }

    #[Test]
    public function reading_an_entity_is_a_get_on_its_key(): void
    {
        $this->firebase->willRespondWith(['firstName' => 'Ada', 'lastName' => 'Lovelace']);

        $user = $this->users->find('user-1');

        $this->assertSame('GET', $this->firebase->lastRequest()->getMethod());
        $this->assertSame('/users/user-1.json', $this->firebase->lastRequest()->getUri()->getPath());
        $this->assertNotNull($user);
        $this->assertSame('Ada', $user->firstName);
        $this->assertSame('user-1', $user->id());
    }

    #[Test]
    public function saving_a_new_entity_posts_to_the_collection(): void
    {
        $this->firebase->willRespondWith(['name' => '-NxGeneratedKey']);

        $saved = $this->users->save(User::named('Ada', 'Lovelace'));

        $this->assertSame('POST', $this->firebase->lastRequest()->getMethod());
        $this->assertSame('/users.json', $this->firebase->lastRequest()->getUri()->getPath());
        $this->assertSame('-NxGeneratedKey', $saved->id());
    }

    #[Test]
    public function saving_an_identified_entity_puts_to_its_key_without_the_id(): void
    {
        $this->firebase->willRespondWith(null);

        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->assertSame('PUT', $this->firebase->lastRequest()->getMethod());
        $this->assertSame('/users/user-1.json', $this->firebase->lastRequest()->getUri()->getPath());
        $this->assertSame(
            ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'role' => 'member', 'tags' => [], 'note' => ''],
            $this->firebase->lastBody(),
        );
    }

    #[Test]
    public function saving_identified_entities_takes_a_single_multi_path_update(): void
    {
        $this->firebase->willRespondWith(null);

        $this->users->saveMany([
            User::named('Ada', 'Lovelace')->withId('user-1'),
            User::named('Grace', 'Hopper')->withId('user-2'),
        ]);

        $this->assertSame(1, $this->firebase->requestCount());
        $this->assertSame('PATCH', $this->firebase->lastRequest()->getMethod());
        $this->assertSame('/users.json', $this->firebase->lastRequest()->getUri()->getPath());

        $body = $this->firebase->lastBody();
        $this->assertSame(['user-1', 'user-2'], array_keys($body));
        $this->assertSame('Grace', $body['user-2']['firstName']);
    }

    #[Test]
    public function a_batch_still_rejects_a_key_the_database_would_refuse(): void
    {
        $this->expectException(InvalidEntity::class);

        $this->users->saveMany([User::named('Ada', 'Lovelace')->withId('a.dotted.key')]);
    }

    #[Test]
    public function a_transaction_reads_with_a_version_tag_and_writes_it_back(): void
    {
        $this->firebase
            ->willRespondWithETag(['firstName' => 'Ada', 'age' => 36], 'etag-1')
            ->willRespondWith(null);

        $this->users->modify('user-1', static function (User $user): User {
            ++$user->age;

            return $user;
        });

        // The read asks for a tag, and the write is conditional on it.
        $this->assertSame('true', $this->firebase->requestAt(0)->getHeaderLine('X-Firebase-ETag'));
        $this->assertSame('PUT', $this->firebase->requestAt(1)->getMethod());
        $this->assertSame('etag-1', $this->firebase->requestAt(1)->getHeaderLine('if-match'));
        $this->assertSame(37, $this->firebase->lastBody()['age']);
    }

    #[Test]
    public function a_transaction_that_loses_the_race_is_retried(): void
    {
        $this->firebase
            // Someone else writes between our read and our write.
            ->willRespondWithETag(['age' => 36], 'etag-1')
            ->willRespondWith(['error' => 'Precondition Failed'], 412)
            // The retry reads the newer value and writes it back successfully.
            ->willRespondWithETag(['age' => 40], 'etag-2')
            ->willRespondWith(null);

        $result = $this->users->modify('user-1', static function (User $user): User {
            ++$user->age;

            return $user;
        });

        $this->assertSame(41, $result->age, 'The retry should have started from the newer value.');
        $this->assertSame('etag-2', $this->firebase->lastRequest()->getHeaderLine('if-match'));
    }

    #[Test]
    public function a_sentinel_written_inside_a_transaction_is_resolved(): void
    {
        // Transaction writes go straight to the underlying client, so they need
        // the same sentinel handling as every other write path.
        $this->firebase
            ->willRespondWithETag(['age' => 36], 'etag-1')
            ->willRespondWith(null);

        $this->users->modify('user-1', static function (User $user): User {
            $user->updatedAt = Sentinel::ServerTimestamp;

            return $user;
        });

        $this->assertSame(['.sv' => 'timestamp'], $this->firebase->lastBody()['updatedAt']);
    }

    #[Test]
    public function a_failure_that_is_not_a_conflict_is_not_retried(): void
    {
        // kreait reports every failed write inside a transaction as a failed
        // transaction. Retrying one that will never succeed wastes five round
        // trips and then reports contention that never happened.
        $this->firebase
            ->willRespondWithETag(['age' => 36], 'etag-1')
            ->willRespondWith(['error' => 'Permission denied'], 401);

        try {
            $this->users->modify('user-1', static fn (User $user): User => $user);
            $this->fail('The failure should have been raised.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(TransactionConflict::class, $e);
        }

        // One read and one write, not five of each.
        $this->assertSame(2, $this->firebase->requestCount());
    }

    #[Test]
    public function giving_up_says_what_the_last_attempt_actually_failed_with(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            $this->firebase
                ->willRespondWithETag(['age' => 36], 'etag-'.$i)
                ->willRespondWith(['error' => 'Precondition Failed'], 412);
        }

        try {
            $this->users->modify('user-1', static fn (User $user): User => $user, attempts: 2);
            $this->fail('The transaction should have given up.');
        } catch (TransactionConflict $e) {
            $this->assertStringContainsString('after 2 attempts', $e->getMessage());
            $this->assertStringContainsString('The last attempt failed with', $e->getMessage());
        }
    }

    #[Test]
    public function a_transaction_that_keeps_losing_eventually_gives_up(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->firebase
                ->willRespondWithETag(['age' => 36], 'etag-'.$i)
                ->willRespondWith(['error' => 'Precondition Failed'], 412);
        }

        $this->expectException(TransactionConflict::class);
        $this->expectExceptionMessage('after 3 attempts');

        $this->users->modify('user-1', static fn (User $user): User => $user, attempts: 3);
    }

    #[Test]
    public function updating_selected_fields_is_a_patch(): void
    {
        $this->firebase->willRespondWith(null);

        $this->users->update('user-1', ['lastName' => 'King']);

        $this->assertSame('PATCH', $this->firebase->lastRequest()->getMethod());
        $this->assertSame(['lastName' => 'King'], $this->firebase->lastBody());
    }

    #[Test]
    public function deleting_is_a_delete_on_the_key_or_the_collection(): void
    {
        $this->firebase->willRespondWith(null)->willRespondWith(null);

        $this->users->delete('user-1');
        $this->assertSame('DELETE', $this->firebase->lastRequest()->getMethod());
        $this->assertSame('/users/user-1.json', $this->firebase->lastRequest()->getUri()->getPath());

        $this->users->deleteAll();
        $this->assertSame('/users.json', $this->firebase->lastRequest()->getUri()->getPath());
    }

    #[Test]
    public function an_equality_filter_becomes_order_by_child_and_equal_to(): void
    {
        $this->firebase->willRespondWith([]);

        $this->users->query()->whereEquals('role', 'admin')->fetch();

        $this->assertSame(['orderBy' => '"role"', 'equalTo' => '"admin"'], $this->firebase->lastQuery());
    }

    #[Test]
    public function range_filters_become_start_at_and_end_at(): void
    {
        $this->firebase->willRespondWith([]);

        $this->users->query()
            ->where('age', Operator::GreaterThanOrEqual, 18)
            ->where('age', Operator::LessThan, 65)
            ->fetch();

        $query = $this->firebase->lastQuery();
        $this->assertSame('"age"', $query['orderBy']);
        $this->assertSame('18', $query['startAt']);
        $this->assertSame('65', $query['endBefore']);
    }

    #[Test]
    public function a_limit_without_an_ordering_is_given_one(): void
    {
        // The database rejects a limit on its own: "orderBy must be defined
        // when other query parameters are defined". Taking the first n of a
        // keyed collection means in key order.
        $this->firebase->willRespondWith([]);

        $this->users->query()->limit(5)->fetch();

        $query = $this->firebase->lastQuery();

        $this->assertSame('"$key"', $query['orderBy']);
        $this->assertSame('5', $query['limitToFirst']);
    }

    #[Test]
    public function an_unfiltered_unlimited_read_asks_for_no_ordering(): void
    {
        $this->firebase->willRespondWith([]);

        $this->connection->readAll('users', Criteria::none());

        $this->assertSame([], $this->firebase->lastQuery());
    }

    #[Test]
    public function ordering_by_key_uses_the_key_token(): void
    {
        $this->firebase->willRespondWith([]);

        $this->users->query()->orderByKey()->fetch();

        $this->assertSame('"$key"', $this->firebase->lastQuery()['orderBy']);
    }

    #[Test]
    public function an_ascending_limit_asks_for_the_first_n(): void
    {
        $this->firebase->willRespondWith([]);

        $this->users->query()->orderBy('age')->limit(10)->fetch();

        $this->assertSame('10', $this->firebase->lastQuery()['limitToFirst']);
    }

    #[Test]
    public function a_descending_limit_asks_for_the_last_n_and_flips_the_result(): void
    {
        // The database only sorts ascending, so the newest three are the last
        // three, handed back in reverse.
        $this->firebase->willRespondWith([
            'user-1' => ['firstName' => 'Ada', 'age' => 36],
            'user-2' => ['firstName' => 'Alan', 'age' => 41],
            'user-3' => ['firstName' => 'Grace', 'age' => 85],
        ]);

        $found = $this->users->query()->orderBy('age', Direction::Descending)->limit(3)->fetch();

        $this->assertSame('3', $this->firebase->lastQuery()['limitToLast']);
        $this->assertSame(['user-3', 'user-2', 'user-1'], array_keys($found));
    }

    #[Test]
    public function filtering_without_ordering_derives_the_ordering(): void
    {
        $this->firebase->willRespondWith([]);

        $this->connection->readAll('users', Criteria::none()->where('role', Operator::Equal, 'admin'));

        $this->assertSame('"role"', $this->firebase->lastQuery()['orderBy']);
    }

    #[Test]
    public function it_refuses_to_filter_on_two_fields(): void
    {
        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('one field only');

        $this->users->query()->whereEquals('role', 'admin')->whereEquals('age', 36)->fetch();
    }

    #[Test]
    public function it_refuses_to_order_by_a_field_it_does_not_filter_on(): void
    {
        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('must order by the field it filters on');

        $this->users->query()->whereEquals('role', 'admin')->orderBy('age')->fetch();
    }

    #[Test]
    public function it_refuses_more_than_one_ordering(): void
    {
        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('order by one field only');

        $this->users->query()->orderBy('age')->orderBy('lastName')->fetch();
    }

    #[Test]
    public function it_refuses_operators_the_database_does_not_have(): void
    {
        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('no "in" operator');

        $this->users->query()->where('role', Operator::In, ['admin', 'member'])->fetch();
    }

    #[Test]
    public function it_rejects_a_key_the_database_would_refuse(): void
    {
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('not a usable Realtime Database key');

        $this->connection->read('users', 'a.dotted.key');
    }

    #[Test]
    public function an_empty_collection_reads_as_an_empty_array(): void
    {
        $this->firebase->willRespondWith(null);

        $this->assertSame([], $this->users->all());
        $this->assertSame(0, $this->firebase->lastQuery() === [] ? 0 : 1);
    }

    #[Test]
    public function it_exposes_the_underlying_reference_as_an_escape_hatch(): void
    {
        $this->assertSame(
            FakeDatabase::URI.'/users',
            (string) $this->connection->reference('users')->getUri(),
        );
    }
}
