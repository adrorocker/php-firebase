<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use JsonSerializable;

/**
 * A value that the database resolves at write time rather than one you supply.
 *
 * Assign one to a property and the connection turns it into whatever its
 * backend understands:
 *
 *     $order->updatedAt = Sentinel::ServerTimestamp;
 *     $orders->save($order);
 *
 * A sentinel only means something on the way in. What comes back is the
 * resolved value - a timestamp, or nothing at all for a removed field.
 *
 * Until then it stands for a value the server has not decided yet, so it
 * encodes as null. Without that, an entity holding one could not be passed to
 * json_encode at all: a pure enum has no JSON form, and the failure would
 * surface far from its cause. Extraction checks for a sentinel before it
 * checks for JsonSerializable, so this does not affect what gets stored.
 */
enum Sentinel implements JsonSerializable
{
    /**
     * The moment the server accepted the write, which is the only clock both
     * ends of your system agree on.
     */
    case ServerTimestamp;

    /**
     * Remove the field entirely, as opposed to storing null.
     */
    case Remove;

    public function jsonSerialize(): mixed
    {
        return null;
    }
}
