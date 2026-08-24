<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Connection;
use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\RealtimeDatabaseConnection;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\Role;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The point of the Connection port: one entity, one repository, three very
 * different stores underneath, and identical results out.
 */
final class BackendParityTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): array{Connection, callable(mixed): void}}>
     */
    public static function backends(): iterable
    {
        yield 'realtime database' => [static function (): array {
            $firebase = new FakeDatabase();

            return [
                new RealtimeDatabaseConnection($firebase->database()),
                static function (mixed $record) use ($firebase): void {
                    $firebase->willRespondWith($record);
                },
            ];
        }];

        yield 'firestore' => [static function (): array {
            $http = new FakeHttpClient();

            return [
                new FirestoreConnection(
                    $http->client(),
                    $http->factory(),
                    $http->factory(),
                    'test-project',
                ),
                static function (mixed $record) use ($http): void {
                    $http->willRespondWith($record);
                },
            ];
        }];
    }

    #[Test]
    #[DataProvider('backends')]
    public function the_same_entity_is_read_identically_from_every_backend(callable $build): void
    {
        [$connection, $respond] = $build();

        $respond($this->storedUser($connection));

        $user = (new UserRepository($connection))->find('user-1');

        $this->assertNotNull($user);
        $this->assertSame('Ada', $user->firstName);
        $this->assertSame(36, $user->age);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame(['maths', 'engines'], $user->tags);
        $this->assertSame('2024-03-01T10:00:00+00:00', $user->joinedAt?->format('c'));
        $this->assertSame('London', $user->address?->city);
        $this->assertSame('user-1', $user->id());
    }

    #[Test]
    public function an_entity_survives_a_full_round_trip_through_a_backend(): void
    {
        $users = new UserRepository(new ArrayConnection());

        $ada = User::named('Ada', 'Lovelace');
        $ada->age = 36;
        $ada->role = Role::Admin;
        $ada->tags = ['maths', 'engines'];
        $ada->joinedAt = new \DateTimeImmutable('2024-03-01T10:00:00+00:00');

        $saved = $users->save($ada);
        $read = $users->get((string) $saved->id());

        $this->assertSame($ada->firstName, $read->firstName);
        $this->assertSame($ada->age, $read->age);
        $this->assertSame($ada->role, $read->role);
        $this->assertSame($ada->tags, $read->tags);
        $this->assertEquals($ada->joinedAt, $read->joinedAt);
    }

    /**
     * The same record, in whatever shape the backend in question stores it.
     */
    private function storedUser(Connection $connection): mixed
    {
        $plain = [
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'age' => 36,
            'role' => 'admin',
            'tags' => ['maths', 'engines'],
            'joinedAt' => '2024-03-01T10:00:00+00:00',
            'address' => ['street' => '1 Analytical Way', 'city' => 'London'],
        ];

        if (!$connection instanceof FirestoreConnection) {
            return $plain;
        }

        return [
            'name' => 'projects/test-project/databases/(default)/documents/users/user-1',
            'fields' => [
                'firstName' => ['stringValue' => 'Ada'],
                'lastName' => ['stringValue' => 'Lovelace'],
                'age' => ['integerValue' => '36'],
                'role' => ['stringValue' => 'admin'],
                'tags' => ['arrayValue' => ['values' => [['stringValue' => 'maths'], ['stringValue' => 'engines']]]],
                'joinedAt' => ['timestampValue' => '2024-03-01T10:00:00+00:00'],
                'address' => ['mapValue' => ['fields' => [
                    'street' => ['stringValue' => '1 Analytical Way'],
                    'city' => ['stringValue' => 'London'],
                ]]],
            ],
        ];
    }
}
