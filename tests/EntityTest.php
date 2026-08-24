<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use DateTimeImmutable;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Tests\Fixtures\Address;
use PhpFirebase\Tests\Fixtures\Level;
use PhpFirebase\Tests\Fixtures\Role;
use PhpFirebase\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\PhpFirebase\Entity::class)]
#[CoversClass(\PhpFirebase\Hydration\Hydrator::class)]
final class EntityTest extends TestCase
{
    #[Test]
    public function a_new_entity_has_no_id(): void
    {
        $user = User::named('Ada', 'Lovelace');

        $this->assertNull($user->id());
        $this->assertFalse($user->hasId());
    }

    #[Test]
    public function with_id_returns_a_copy_and_leaves_the_original_alone(): void
    {
        $user = User::named('Ada', 'Lovelace');
        $identified = $user->withId('user-1');

        $this->assertSame('user-1', $identified->id());
        $this->assertNull($user->id());
        $this->assertNotSame($user, $identified);
    }

    #[Test]
    public function the_database_payload_leaves_out_the_id(): void
    {
        $user = User::named('Ada', 'Lovelace')->withId('user-1');

        $this->assertArrayNotHasKey('id', $user->toDatabase());
        $this->assertSame('user-1', $user->toArray()['id']);
    }

    #[Test]
    public function private_properties_are_not_persisted(): void
    {
        $user = User::named('Ada', 'Lovelace');

        $this->assertArrayNotHasKey('secret', $user->toDatabase());
    }

    #[Test]
    public function protected_properties_are_persisted(): void
    {
        $user = User::named('Ada', 'Lovelace')->withNote('a note');

        $this->assertSame('a note', $user->toDatabase()['note']);
        $this->assertSame('a note', User::fromArray($user->toDatabase())->note());
    }

    #[Test]
    public function null_properties_are_left_out_so_they_do_not_delete_keys(): void
    {
        $payload = User::named('Ada', 'Lovelace')->toDatabase();

        $this->assertArrayNotHasKey('age', $payload);
        $this->assertArrayNotHasKey('joinedAt', $payload);
    }

    #[Test]
    public function enums_dates_and_nested_entities_survive_a_round_trip(): void
    {
        $user = User::named('Ada', 'Lovelace');
        $user->age = 36;
        $user->role = Role::Admin;
        $user->level = Level::High;
        $user->joinedAt = new DateTimeImmutable('2024-03-01T10:00:00+00:00');
        $user->address = new Address();
        $user->address->street = '1 Analytical Way';
        $user->address->city = 'London';
        $user->tags = ['maths', 'engines'];

        $payload = $user->toDatabase();

        $this->assertSame('admin', $payload['role']);
        $this->assertSame(2, $payload['level']);
        $this->assertSame('2024-03-01T10:00:00+00:00', $payload['joinedAt']);
        $this->assertSame(['street' => '1 Analytical Way', 'city' => 'London'], $payload['address']);

        $restored = User::fromArray($payload);

        $this->assertSame(Role::Admin, $restored->role);
        $this->assertSame(Level::High, $restored->level);
        $this->assertEquals($user->joinedAt, $restored->joinedAt);
        $this->assertSame('London', $restored->address?->city);
        $this->assertSame(['maths', 'engines'], $restored->tags);
    }

    #[Test]
    public function an_int_backed_enum_survives_being_read_back_as_a_float(): void
    {
        $user = User::fromArray(['firstName' => 'Ada', 'level' => 2.0]);

        $this->assertSame(Level::High, $user->level);
    }

    #[Test]
    public function a_date_is_read_from_unix_milliseconds(): void
    {
        $user = User::fromArray(['joinedAt' => 1_709_287_200_000]);

        $this->assertSame('2024-03-01T10:00:00+00:00', $user->joinedAt?->format(DateTimeImmutable::ATOM));
    }

    #[Test]
    public function a_date_is_read_from_milliseconds_even_as_a_numeric_string(): void
    {
        $user = User::fromArray(['joinedAt' => '1709287200000']);

        $this->assertSame('2024-03-01T10:00:00+00:00', $user->joinedAt?->format(DateTimeImmutable::ATOM));
    }

    #[Test]
    public function a_short_numeric_date_is_left_to_the_parser(): void
    {
        $user = User::fromArray(['joinedAt' => '2024-03-01']);

        $this->assertSame('2024-03-01', $user->joinedAt?->format('Y-m-d'));
    }

    #[Test]
    public function a_loosely_typed_number_is_still_read_into_a_typed_property(): void
    {
        $user = User::fromArray(['age' => '36']);

        $this->assertSame(36, $user->age);
    }

    #[Test]
    public function hydration_bypasses_the_constructor_and_ignores_unknown_keys(): void
    {
        $user = User::fromArray(['firstName' => 'Ada', 'unknown' => 'ignored'], 'user-1');

        $this->assertSame('Ada', $user->firstName);
        $this->assertSame('user-1', $user->id());
        $this->assertSame('not persisted', $user->secret());
    }

    #[Test]
    public function the_id_is_read_from_the_payload_when_no_key_is_given(): void
    {
        $this->assertSame('user-1', User::fromArray(['id' => 'user-1'])->id());
    }

    #[Test]
    public function the_key_wins_over_an_id_in_the_payload(): void
    {
        $this->assertSame('key', User::fromArray(['id' => 'stale'], 'key')->id());
    }

    #[Test]
    public function it_round_trips_through_json(): void
    {
        $user = User::named('Ada', 'Lovelace')->withId('user-1');

        $restored = User::fromJson($user->toJson());

        $this->assertSame('user-1', $restored->id());
        $this->assertSame('Lovelace', $restored->lastName);
    }

    #[Test]
    public function json_encoding_an_entity_uses_its_array_form(): void
    {
        $user = User::named('Ada', 'Lovelace')->withId('user-1');

        $this->assertSame($user->toArray(), json_decode($user->toJson(), true));
    }

    #[Test]
    public function it_rejects_json_that_is_not_an_object(): void
    {
        $this->expectException(InvalidEntity::class);

        User::fromJson('"a string"');
    }

    #[Test]
    public function it_rejects_an_unknown_enum_case(): void
    {
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage("'wizard'");

        User::fromArray(['role' => 'wizard']);
    }

    #[Test]
    public function it_rejects_a_nested_entity_that_is_not_a_record(): void
    {
        $this->expectException(InvalidEntity::class);

        User::fromArray(['address' => 'not a record']);
    }
}
