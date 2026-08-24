<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use DateTimeImmutable;
use PhpFirebase\Entity;
use PhpFirebase\EntityRepository;
use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Tests\Fixtures\ArrayConnection;
use PhpFirebase\Tests\Fixtures\Coupon;
use PhpFirebase\Tests\Fixtures\HalfBuilt;
use PhpFirebase\Tests\Fixtures\LineItem;
use PhpFirebase\Tests\Fixtures\LooseBag;
use PhpFirebase\Tests\Fixtures\Money;
use PhpFirebase\Tests\Fixtures\Order;
use PhpFirebase\Tests\Fixtures\OrderStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The library has no idea what an entity is meant to contain. These tests use a
 * domain model that shares nothing with the User fixture, including plain value
 * objects that know nothing about persistence.
 */
#[CoversClass(Entity::class)]
#[CoversClass(\PhpFirebase\Hydration\Hydrator::class)]
final class ArbitraryEntityTest extends TestCase
{
    /**
     * @return EntityRepository<Order>
     */
    private function orders(): EntityRepository
    {
        return new EntityRepository(new ArrayConnection(), 'orders', Order::class);
    }

    private function order(): Order
    {
        $order = new Order();
        $order->reference = 'ORD-1';
        $order->status = OrderStatus::Shipped;
        $order->total = new Money(1999, 'EUR');
        $order->placedAt = new DateTimeImmutable('2024-03-01T10:00:00+00:00');

        $line = new LineItem();
        $line->sku = 'AB-1';
        $line->quantity = 2;
        $line->price = new Money(999, 'EUR');
        $order->lines = [$line];

        return $order;
    }

    #[Test]
    public function a_plain_value_object_is_stored_as_its_properties(): void
    {
        $payload = $this->order()->toDatabase();

        $this->assertSame(['amount' => 1999, 'currency' => 'EUR'], $payload['total']);
    }

    #[Test]
    public function a_plain_value_object_is_read_back_as_itself(): void
    {
        $order = Order::fromArray(['total' => ['amount' => 1999, 'currency' => 'EUR']]);

        $this->assertInstanceOf(Money::class, $order->total);
        $this->assertSame(1999, $order->total->amount);
        $this->assertSame('EUR', $order->total->currency);
    }

    #[Test]
    public function a_whole_aggregate_survives_a_round_trip(): void
    {
        $orders = $this->orders();

        $saved = $orders->save($this->order());
        $read = $orders->get((string) $saved->id());

        $this->assertSame('ORD-1', $read->reference);
        $this->assertSame(OrderStatus::Shipped, $read->status);
        $this->assertEquals(new Money(1999, 'EUR'), $read->total);
        $this->assertEquals($this->order()->placedAt, $read->placedAt);
        $this->assertCount(1, $read->lines);
        $this->assertSame('AB-1', $read->lines[0]->sku);
        $this->assertEquals(new Money(999, 'EUR'), $read->lines[0]->price);
    }

    #[Test]
    public function nested_entities_inside_a_list_keep_their_type(): void
    {
        $orders = $this->orders();
        $read = $orders->get((string) $orders->save($this->order())->id());

        // The docblock promises LineItem; this checks what actually came back,
        // since a mapper that ignored it would hand over plain arrays.
        $this->assertSame([LineItem::class], array_unique(array_map(get_debug_type(...), $read->lines)));
    }

    #[Test]
    public function an_entity_built_entirely_through_its_constructor_round_trips(): void
    {
        // The DDD-shaped alternative: every persisted property is promoted, so
        // the object cannot exist half-built.
        $coupons = new EntityRepository(new ArrayConnection(), 'coupons', Coupon::class);

        $saved = $coupons->save(new Coupon('SUMMER', 20, new Money(5000, 'EUR')));
        $read = $coupons->get((string) $saved->id());

        $this->assertSame('SUMMER', $read->code);
        $this->assertSame(20, $read->percentOff);
        $this->assertEquals(new Money(5000, 'EUR'), $read->minimumSpend);
    }

    #[Test]
    public function a_constructor_that_covers_only_some_properties_is_refused(): void
    {
        // Valinor builds through the constructor when there is one, which would
        // leave $note at its default and lose whatever was stored.
        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('$note');

        HalfBuilt::fromArray(['title' => 'A title', 'note' => 'A note']);
    }

    #[Test]
    public function an_object_php_itself_owns_cannot_be_stored(): void
    {
        // A typed property would have been rejected by PHP already; this is the
        // loosely typed case, where the mapper is the only thing checking.
        $bag = new LooseBag();
        $bag->anything = new \ArrayObject([1, 2]);

        $this->expectException(InvalidEntity::class);
        $this->expectExceptionMessage('Cannot store a ArrayObject');

        $bag->toDatabase();
    }

    #[Test]
    public function a_resource_cannot_be_stored(): void
    {
        $bag = new LooseBag();
        $bag->anything = fopen('php://memory', 'r');

        try {
            $this->expectException(InvalidEntity::class);
            $this->expectExceptionMessage('resource');

            $bag->toDatabase();
        } finally {
            if (is_resource($bag->anything)) {
                fclose($bag->anything);
            }
        }
    }
}
