<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Fixtures;

use DateTimeImmutable;
use PhpFirebase\Entity;

/**
 * An aggregate that looks nothing like a User, which is the point: the library
 * has no notion of what an entity is supposed to contain.
 */
final class Order extends Entity
{
    public OrderStatus $status = OrderStatus::Pending;

    public ?Money $total = null;

    /** @var list<LineItem> */
    public array $lines = [];

    public ?DateTimeImmutable $placedAt = null;

    public string $reference = '';
}
