<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\EntityTransaction;
use PhpFirebase\Exception\EntityNotFound;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTransaction::class)]
#[CoversClass(\PhpFirebase\Repository::class)]
final class TransactionTest extends TestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->users = new UserRepository(new ArrayConnection());

        $ada = User::named('Ada', 'Lovelace');
        $ada->age = 36;
        $this->users->save($ada->withId('ada'));
    }

    #[Test]
    public function modify_reads_changes_and_writes_back(): void
    {
        $written = $this->users->modify('ada', static function (User $user): User {
            ++$user->age;

            return $user;
        });

        $this->assertSame(37, $written->age);
        $this->assertSame(37, $this->users->get('ada')->age);
    }

    #[Test]
    public function modify_insists_the_entity_exists(): void
    {
        $this->expectException(EntityNotFound::class);

        $this->users->modify('nobody', static fn (User $user): User => $user);
    }

    #[Test]
    public function a_transaction_can_read_and_write_several_entities(): void
    {
        $this->users->save(User::named('Grace', 'Hopper')->withId('grace'));

        $this->users->transaction(function (EntityTransaction $users): void {
            $ada = $users->get('ada');
            $grace = $users->get('grace');

            $grace->age = $ada->age;

            $users->save($grace);
        });

        $this->assertSame(36, $this->users->get('grace')->age);
    }

    #[Test]
    public function a_transaction_returns_whatever_the_work_returns(): void
    {
        $name = $this->users->transaction(
            static fn (EntityTransaction $users): string => $users->get('ada')->lastName,
        );

        $this->assertSame('Lovelace', $name);
    }

    #[Test]
    public function find_inside_a_transaction_returns_null_for_a_missing_entity(): void
    {
        $found = $this->users->transaction(
            static fn (EntityTransaction $users): ?User => $users->find('nobody'),
        );

        $this->assertNull($found);
    }

    #[Test]
    public function a_transaction_can_delete(): void
    {
        $this->users->transaction(static function (EntityTransaction $users): void {
            $users->delete('ada');
        });

        $this->assertNull($this->users->find('ada'));
    }

    #[Test]
    public function saving_an_entity_without_an_id_inside_a_transaction_is_refused(): void
    {
        // A transaction protects a known location; there is nothing to protect
        // about a record whose id does not exist yet.
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('has no id');

        $this->users->transaction(static function (EntityTransaction $users): void {
            $users->save(User::named('Grace', 'Hopper'));
        });
    }
}
