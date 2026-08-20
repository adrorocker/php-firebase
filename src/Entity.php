<?php

declare(strict_types=1);

namespace PhpFirebase;

use PhpFirebase\Exception\InvalidEntity;
use PhpFirebase\Hydration\Hydrator;

/**
 * Base class for records stored in the Realtime Database.
 *
 * Declare the fields you want persisted as public or protected properties:
 *
 *     final class User extends Entity
 *     {
 *         public string $firstName;
 *         public string $lastName;
 *         public ?Role $role = null;
 *     }
 *
 * Private properties are never written or read, which is what keeps `$id`
 * below out of the payload.
 *
 * An entity may be built either way round, and both are supported:
 *
 * - **No constructor.** Properties are filled directly. Simplest, and the right
 *   choice for records that grow fields over time.
 * - **Every persisted property promoted into the constructor.** The object can
 *   then never exist half-built, which is what you want for an aggregate that
 *   has invariants to protect.
 *
 * A constructor covering only *some* of the persisted properties is refused,
 * because the rest would silently keep their defaults on read and overwrite the
 * stored values on the next save.
 */
abstract class Entity implements EntityInterface
{
    private ?string $id = null;

    final public function id(): ?string
    {
        return $this->id;
    }

    final public function hasId(): bool
    {
        return $this->id !== null;
    }

    final public function withId(string $id): static
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->toDatabase();

        if ($this->id !== null) {
            $data = ['id' => $this->id] + $data;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(): array
    {
        return (new Hydrator())->extract($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $id = null): static
    {
        $id ??= isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : null;
        unset($data['id']);

        $entity = (new Hydrator())->hydrate(static::class, $data);

        return $id !== null ? $entity->withId($id) : $entity;
    }

    /**
     * @throws InvalidEntity if the JSON does not describe an object
     */
    public static function fromJson(string $json, ?string $id = null): static
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new InvalidEntity(sprintf(
                'Expected the JSON to describe a %s object, got %s.',
                static::class,
                get_debug_type($data),
            ));
        }

        return static::fromArray($data, $id);
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this, $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
