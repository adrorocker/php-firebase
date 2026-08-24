<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;
use PhpFirebase\Query;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\Role;
use PhpFirebase\Tests\Fixtures\User;
use PhpFirebase\Tests\Fixtures\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Query::class)]
#[CoversClass(\PhpFirebase\Database\Criteria::class)]
final class QueryTest extends TestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->users = new UserRepository(new ArrayConnection());

        foreach ([['Ada', 'Lovelace', 36], ['Grace', 'Hopper', 85], ['Alan', 'Turing', 41]] as [$first, $last, $age]) {
            $user = User::named($first, $last);
            $user->age = $age;
            $this->users->save($user->withId(strtolower($first)));
        }
    }

    #[Test]
    public function it_filters_by_equality(): void
    {
        $found = $this->users->query()->whereEquals('lastName', 'Hopper')->fetch();

        $this->assertSame(['grace'], array_keys($found));
    }

    #[Test]
    public function a_backed_enum_may_be_compared_directly(): void
    {
        // Entities store an enum as its value, so filtering by the case itself
        // is what a caller naturally writes.
        $found = $this->users->query()->whereEquals('role', Role::Member)->fetch();

        $this->assertSame(['ada', 'grace', 'alan'], array_keys($found));
        $this->assertSame(
            'member',
            $this->users->query()->whereEquals('role', Role::Member)->criteria()->conditions[0]->value,
        );
    }

    #[Test]
    public function a_list_of_enums_is_unwrapped_too(): void
    {
        $criteria = $this->users->query()
            ->where('role', Operator::In, [Role::Admin, Role::Member])
            ->criteria();

        $this->assertSame(['admin', 'member'], $criteria->conditions[0]->value);
    }

    #[Test]
    public function it_filters_by_range(): void
    {
        $found = $this->users->query()->where('age', Operator::GreaterThan, 40)->fetch();

        $this->assertSame(['grace', 'alan'], array_keys($found));
    }

    #[Test]
    public function it_orders_ascending_and_descending(): void
    {
        $this->assertSame(
            ['ada', 'alan', 'grace'],
            array_keys($this->users->query()->orderBy('age')->fetch()),
        );

        $this->assertSame(
            ['grace', 'alan', 'ada'],
            array_keys($this->users->query()->orderBy('age', Direction::Descending)->fetch()),
        );
    }

    #[Test]
    public function it_orders_by_key(): void
    {
        $this->assertSame(
            ['ada', 'alan', 'grace'],
            array_keys($this->users->query()->orderByKey()->fetch()),
        );
    }

    #[Test]
    public function it_limits_the_results(): void
    {
        $found = $this->users->query()->orderBy('age')->limit(2)->fetch();

        $this->assertSame(['ada', 'alan'], array_keys($found));
    }

    #[Test]
    public function each_builder_call_returns_a_new_query(): void
    {
        $base = $this->users->query()->orderBy('age');
        $branch = $base->limit(1);

        $this->assertNotSame($base, $branch);
        $this->assertCount(3, $base->fetch());
        $this->assertCount(1, $branch->fetch());
    }

    #[Test]
    public function it_hydrates_entities_carrying_their_id(): void
    {
        $found = $this->users->query()->whereEquals('firstName', 'Ada')->fetch();

        $this->assertSame('ada', $found['ada']->id());
    }

    #[Test]
    public function first_returns_one_entity_or_null(): void
    {
        $this->assertSame('Hopper', $this->users->query()->orderBy('age', Direction::Descending)->first()?->lastName);
        $this->assertNull($this->users->query()->whereEquals('lastName', 'Nobody')->first());
    }

    #[Test]
    public function it_counts_matching_entities(): void
    {
        $this->assertSame(3, $this->users->query()->count());
        $this->assertSame(2, $this->users->query()->where('age', Operator::GreaterThan, 40)->count());
    }

    #[Test]
    public function it_exposes_the_criteria_it_built(): void
    {
        $criteria = $this->users->query()->whereEquals('role', 'admin')->orderBy('age')->limit(5)->criteria();

        $this->assertSame(['role'], $criteria->filteredFields());
        $this->assertSame(5, $criteria->limit);
        $this->assertCount(1, $criteria->orderings);
    }

    #[Test]
    public function it_rejects_a_limit_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->users->query()->limit(0);
    }

    #[Test]
    public function it_rejects_an_empty_field_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->users->query()->whereEquals('  ', 'x');
    }
}
