<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\EntityRepository;
use PhpFirebase\Exception\EntityNotFound;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Tests\Fixtures\Address;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The repository is exercised against an in-memory connection, which is the
 * point of the Connection port: none of this behaviour is Firebase-specific.
 */
#[CoversClass(\PhpFirebase\Repository::class)]
#[CoversClass(EntityRepository::class)]
final class RepositoryTest extends TestCase
{
    private ArrayConnection $connection;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->connection = new ArrayConnection();
        $this->users = new UserRepository($this->connection);
    }

    #[Test]
    public function saving_a_new_entity_gives_it_an_id_from_the_backend(): void
    {
        $saved = $this->users->save(User::named('Ada', 'Lovelace'));

        $this->assertSame('generated-1', $saved->id());
        $this->assertSame('Ada', $this->users->get('generated-1')->firstName);
    }

    #[Test]
    public function saving_leaves_the_original_entity_untouched(): void
    {
        $user = User::named('Ada', 'Lovelace');

        $this->assertNotSame($user, $this->users->save($user));
        $this->assertNull($user->id());
    }

    #[Test]
    public function saving_an_identified_entity_overwrites_it(): void
    {
        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));
        $this->users->save(User::named('Grace', 'Hopper')->withId('user-1'));

        $this->assertSame('Grace', $this->users->get('user-1')->firstName);
        $this->assertSame(1, $this->users->count());
    }

    #[Test]
    public function the_stored_record_never_contains_the_id(): void
    {
        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->assertNotNull($record = $this->connection->read('users', 'user-1'));
        $this->assertArrayNotHasKey('id', $record);
    }

    #[Test]
    public function the_id_is_restored_from_the_key_on_the_way_back(): void
    {
        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->assertSame('user-1', $this->users->get('user-1')->id());
    }

    #[Test]
    public function find_returns_null_when_there_is_nothing(): void
    {
        $this->assertNull($this->users->find('nope'));
    }

    #[Test]
    public function get_throws_when_the_entity_is_missing(): void
    {
        $this->expectException(EntityNotFound::class);
        $this->expectExceptionMessage('users/nope');

        $this->users->get('nope');
    }

    #[Test]
    public function it_saves_many_entities(): void
    {
        $saved = $this->users->saveMany([User::named('Ada', 'Lovelace'), User::named('Grace', 'Hopper')]);

        $this->assertSame(['generated-1', 'generated-2'], array_map(static fn (User $u): ?string => $u->id(), $saved));
        $this->assertCount(2, $this->users->all());
    }

    #[Test]
    public function save_many_keeps_the_order_it_was_given_even_from_a_generator(): void
    {
        $entities = (static function (): iterable {
            // A generator whose keys all collide, which must not lose entries.
            yield User::named('Ada', 'Lovelace')->withId('user-1');
            yield User::named('Grace', 'Hopper');
            yield User::named('Alan', 'Turing')->withId('user-3');
        })();

        $saved = $this->users->saveMany($entities);

        $this->assertSame(
            ['Ada', 'Grace', 'Alan'],
            array_map(static fn (User $u): string => $u->firstName, $saved),
        );
        $this->assertSame(['user-1', 'generated-1', 'user-3'], array_map(static fn (User $u): ?string => $u->id(), $saved));
    }

    #[Test]
    public function it_reads_the_whole_collection_keyed_by_id(): void
    {
        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));
        $this->users->save(User::named('Grace', 'Hopper')->withId('user-2'));

        $users = $this->users->all();

        $this->assertSame(['user-1', 'user-2'], array_keys($users));
        $this->assertSame('Hopper', $users['user-2']->lastName);
    }

    #[Test]
    public function an_empty_collection_reads_as_an_empty_array(): void
    {
        $this->assertSame([], $this->users->all());
        $this->assertSame(0, $this->users->count());
    }

    #[Test]
    public function it_updates_selected_fields_only(): void
    {
        $this->users->save(User::named('Ada', 'Lovelace')->withId('user-1'));

        $this->users->update('user-1', ['lastName' => 'King']);

        $user = $this->users->get('user-1');
        $this->assertSame('King', $user->lastName);
        $this->assertSame('Ada', $user->firstName);
    }

    #[Test]
    public function it_deletes_by_entity_and_by_id(): void
    {
        $ada = $this->users->save(User::named('Ada', 'Lovelace'));
        $this->users->save(User::named('Grace', 'Hopper')->withId('user-2'));

        $this->users->delete($ada);
        $this->users->delete('user-2');

        $this->assertSame([], $this->users->all());
    }

    #[Test]
    public function it_deletes_the_whole_collection(): void
    {
        $this->users->saveMany([User::named('Ada', 'Lovelace'), User::named('Grace', 'Hopper')]);

        $this->users->deleteAll();

        $this->assertSame([], $this->users->all());
    }

    #[Test]
    public function it_refuses_to_delete_an_entity_without_an_id(): void
    {
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('has no id');

        $this->users->delete(User::named('Ada', 'Lovelace'));
    }

    #[Test]
    public function it_refuses_an_entity_of_the_wrong_class(): void
    {
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage(User::class);

        // Deliberately the wrong type: this asserts the runtime guard, which
        // is what protects callers who do not run static analysis.
        /** @phpstan-ignore argument.type */
        $this->users->save(new Address());
    }

    #[Test]
    public function it_rejects_an_id_that_would_escape_the_collection(): void
    {
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('must not be empty or contain a slash');

        $this->users->find('../admins/root');
    }

    #[Test]
    public function it_rejects_an_empty_id(): void
    {
        $this->expectException(InvalidEntity::class);

        $this->users->find('');
    }

    #[Test]
    public function the_ready_made_repository_needs_no_subclass(): void
    {
        $users = new EntityRepository($this->connection, 'users', User::class);

        $saved = $users->save(User::named('Ada', 'Lovelace'));

        $this->assertSame('Ada', $users->get((string) $saved->id())->firstName);
    }

    #[Test]
    public function the_ready_made_repository_refuses_a_class_that_is_not_an_entity(): void
    {
        $this->expectException(InvalidEntity::class);

        /** @phpstan-ignore argument.type */
        new EntityRepository($this->connection, 'users', \stdClass::class);
    }
}
