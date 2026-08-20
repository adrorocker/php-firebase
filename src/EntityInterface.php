<?php

declare(strict_types=1);

namespace PhpFirebase;

use JsonSerializable;

/**
 * A record that can be stored under a key in the Realtime Database.
 */
interface EntityInterface extends JsonSerializable
{
    /**
     * The database key this entity was read from, or null while it is new.
     */
    public function id(): ?string;

    /**
     * Returns a copy carrying the given database key.
     */
    public function withId(string $id): static;

    /**
     * The entity as a plain array, including its id when it has one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * The entity as the database stores it: the payload without the id, since
     * the id is the key the payload is stored under.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(): array;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $id = null): static;
}
