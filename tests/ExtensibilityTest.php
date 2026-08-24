<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\Firestore\QueryTranslator;
use PhpFirebase\Database\RealtimeDatabaseConnection;
use PhpFirebase\Exception\UnsupportedQuery;
use PhpFirebase\Query;
use PhpFirebase\Repository;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A cursor is deliberately not part of this package, because the Realtime
 * Database cannot express one. These tests build it from outside anyway, which
 * is what the extension points are for.
 */
final class ExtensibilityTest extends TestCase
{
    #[Test]
    public function a_connection_refuses_an_extra_it_does_not_understand(): void
    {
        $http = new FakeHttpClient();
        $connection = new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project');

        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('"startAfter"');

        $connection->readAll('users', Criteria::none()->withExtra('startAfter', ['ada']));
    }

    #[Test]
    public function the_realtime_database_refuses_it_too_rather_than_ignoring_it(): void
    {
        // The dangerous failure would be answering a different query silently.
        $firebase = new FakeDatabase();
        $connection = new RealtimeDatabaseConnection($firebase->database());

        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessage('"startAfter"');

        $connection->readAll('users', Criteria::none()->withExtra('startAfter', ['ada']));
    }

    #[Test]
    public function a_third_party_can_add_a_cursor_without_forking(): void
    {
        $http = new FakeHttpClient();
        $http->willRespondWith([]);

        $connection = new CursorFirestoreConnection(
            $http->client(),
            $http->factory(),
            $http->factory(),
            'test-project',
            translator: new CursorQueryTranslator(),
        );

        $connection->readAll('users', Criteria::none()->orderBy('age')->withExtra('startAfter', [42]));

        $query = $http->lastBody()['structuredQuery'];

        $this->assertSame(['values' => [['integerValue' => '42']], 'before' => false], $query['startAt']);
    }

    #[Test]
    public function a_repository_can_hand_out_its_own_query_type(): void
    {
        $repository = new AdultUserRepository(new ArrayConnection());

        $young = User::named('Kid', 'One');
        $young->age = 10;
        $old = User::named('Ada', 'Lovelace');
        $old->age = 36;
        $repository->saveMany([$young->withId('kid'), $old->withId('ada')]);

        $found = $repository->query()->adults()->fetch();

        $this->assertSame(['ada'], array_keys($found));
    }
}

/**
 * @extends Query<User>
 */
final readonly class UserQuery extends Query
{
    public function adults(): static
    {
        return $this->where('age', \PhpFirebase\Database\Operator::GreaterThanOrEqual, 18);
    }
}

/**
 * @extends Repository<User>
 */
final class AdultUserRepository extends Repository
{
    protected function collection(): string
    {
        return 'users';
    }

    protected function entityClass(): string
    {
        return User::class;
    }

    public function query(): UserQuery
    {
        return new UserQuery($this->connection, $this->collection(), $this->entityClass());
    }
}

final class CursorFirestoreConnection extends FirestoreConnection
{
    protected function supportedExtras(): array
    {
        return ['startAfter'];
    }
}

final readonly class CursorQueryTranslator extends QueryTranslator
{
    public function translate(string $collection, Criteria $criteria): array
    {
        $query = parent::translate($collection, $criteria);
        $cursor = $criteria->extra('startAfter');

        if (is_array($cursor)) {
            $encoder = new \PhpFirebase\Database\Firestore\ValueEncoder();

            $query['startAt'] = [
                'values' => array_map($encoder->encode(...), $cursor),
                'before' => false,
            ];
        }

        return $query;
    }
}
