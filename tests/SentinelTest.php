<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\RealtimeDatabaseConnection;
use PhpFirebase\Database\Sentinel;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sentinel::class)]
final class SentinelTest extends TestCase
{
    private function userWith(mixed $updatedAt): User
    {
        return $this->newUserWith($updatedAt)->withId('user-1');
    }

    /** A user that has never been stored, so saving it has to allocate an id. */
    private function newUserWith(mixed $updatedAt): User
    {
        $user = User::named('Ada', 'Lovelace');
        $user->updatedAt = $updatedAt;

        return $user;
    }

    #[Test]
    public function an_entity_holding_a_sentinel_can_still_be_encoded(): void
    {
        // save() returns the entity you handed it, so it still carries the
        // sentinel: the server has resolved it, but this copy has not been
        // read back. Encoding that must not be fatal.
        $user = $this->userWith(Sentinel::ServerTimestamp);

        $encoded = json_decode($user->toJson(), true);

        $this->assertNull($encoded['updatedAt']);
    }

    #[Test]
    public function the_realtime_database_writes_its_own_server_value(): void
    {
        $firebase = new FakeDatabase();
        $users = new UserRepository(new RealtimeDatabaseConnection($firebase->database()));
        $firebase->willRespondWith(null);

        $users->save($this->userWith(Sentinel::ServerTimestamp));

        $this->assertSame(['.sv' => 'timestamp'], $firebase->lastBody()['updatedAt']);
    }

    #[Test]
    public function the_realtime_database_removes_a_field_by_writing_null(): void
    {
        $firebase = new FakeDatabase();
        $users = new UserRepository(new RealtimeDatabaseConnection($firebase->database()));
        $firebase->willRespondWith(null);

        $users->update('user-1', ['updatedAt' => Sentinel::Remove]);

        $this->assertNull($firebase->lastBody()['updatedAt']);
        $this->assertArrayHasKey('updatedAt', $firebase->lastBody());
    }

    #[Test]
    public function firestore_writes_a_server_timestamp_as_a_transform(): void
    {
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith(['writeResults' => []]);

        $users->save($this->userWith(Sentinel::ServerTimestamp));

        // A transform is only expressible in a commit, so the write goes there.
        $this->assertStringEndsWith(':commit', $http->lastPath());

        $write = $http->lastBody()['writes'][0];
        $this->assertSame(
            [['fieldPath' => 'updatedAt', 'setToServerValue' => 'REQUEST_TIME']],
            $write['updateTransforms'],
        );
        // The field must not also appear among the plain values.
        $this->assertArrayNotHasKey('updatedAt', $write['update']['fields']);
    }

    #[Test]
    public function firestore_removes_a_field_by_masking_it_without_a_value(): void
    {
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith([]);

        $users->update('user-1', ['updatedAt' => Sentinel::Remove, 'lastName' => 'King']);

        $this->assertSame('PATCH', $http->lastRequest()->getMethod());
        $this->assertStringContainsString('updateMask.fieldPaths=updatedAt', urldecode($http->lastQueryString()));
        $this->assertArrayNotHasKey('updatedAt', $http->lastBody()['fields']);
        $this->assertSame(['stringValue' => 'King'], $http->lastBody()['fields']['lastName']);
    }

    #[Test]
    public function firestore_applies_a_transform_when_creating_a_new_document(): void
    {
        // The create path is separate from the update path, and the endpoint
        // that allocates an id cannot carry a transform.
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith(['writeResults' => []]);

        $saved = $users->save($this->newUserWith(Sentinel::ServerTimestamp));

        $this->assertStringEndsWith(':commit', $http->lastPath());

        $write = $http->lastBody()['writes'][0];
        $this->assertSame(
            [['fieldPath' => 'updatedAt', 'setToServerValue' => 'REQUEST_TIME']],
            $write['updateTransforms'],
        );

        // The id has to come back, because nothing else allocated one.
        $this->assertNotNull($saved->id());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', (string) $saved->id());
        $this->assertStringEndsWith('/users/'.$saved->id(), $write['update']['name']);
    }

    #[Test]
    public function firestore_still_uses_the_cheap_endpoint_when_creating_without_a_sentinel(): void
    {
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith([
            'name' => 'projects/test-project/databases/(default)/documents/users/generated',
            'fields' => [],
        ]);

        $saved = $users->save($this->newUserWith(null));

        $this->assertSame('POST', $http->lastRequest()->getMethod());
        $this->assertStringEndsWith('/users', $http->lastPath());
        $this->assertSame('generated', $saved->id());
    }

    #[Test]
    public function the_realtime_database_resolves_a_sentinel_when_pushing_a_new_record(): void
    {
        $firebase = new FakeDatabase();
        $users = new UserRepository(new RealtimeDatabaseConnection($firebase->database()));
        $firebase->willRespondWith(['name' => '-NxGenerated']);

        $saved = $users->save($this->newUserWith(Sentinel::ServerTimestamp));

        $this->assertSame('POST', $firebase->lastRequest()->getMethod());
        $this->assertSame(['.sv' => 'timestamp'], $firebase->lastBody()['updatedAt']);
        $this->assertSame('-NxGenerated', $saved->id());
    }

    #[Test]
    public function firestore_sends_an_empty_map_rather_than_an_empty_list(): void
    {
        // Removing the only named field leaves nothing among the values, and
        // PHP encodes an empty array as [] - which Firestore rejects where it
        // wants a map. Touching only a timestamp does the same thing.
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith([]);

        $users->update('user-1', ['updatedAt' => Sentinel::Remove]);

        $body = (string) $http->lastRequest()->getBody();

        $this->assertStringContainsString('"fields":{}', $body);
        $this->assertStringNotContainsString('"fields":[]', $body);
    }

    #[Test]
    public function firestore_sends_an_empty_map_when_a_commit_carries_only_a_transform(): void
    {
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith(['writeResults' => []]);

        $users->update('user-1', ['updatedAt' => Sentinel::ServerTimestamp]);

        $body = (string) $http->lastRequest()->getBody();

        $this->assertStringContainsString(':commit', $http->lastPath());
        $this->assertStringContainsString('"fields":{}', $body);
        $this->assertStringNotContainsString('"fields":[]', $body);
    }

    #[Test]
    public function a_write_without_sentinels_still_uses_the_plain_document_endpoint(): void
    {
        $http = new FakeHttpClient();
        $users = new UserRepository(new FirestoreConnection($http->client(), $http->factory(), $http->factory(), 'test-project'));
        $http->willRespondWith([]);

        $users->save($this->userWith(null));

        $this->assertSame('PATCH', $http->lastRequest()->getMethod());
        $this->assertStringEndsWith('/users/user-1', $http->lastPath());
    }
}
