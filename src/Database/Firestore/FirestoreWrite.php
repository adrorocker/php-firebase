<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use PhpFirebase\Database\Sentinel;

/**
 * A record split into the parts a Firestore write is made of.
 *
 * The constructor is private and {@see encode()} is the only way to obtain
 * one, so a value of this type has necessarily been through sentinel handling.
 * Every method that builds a Firestore request takes one of these rather than
 * a raw array, which is what stops a new write path from skipping the step.
 *
 * The split exists because Firestore does not store "use the server's clock"
 * as a value: it is a transform applied as the document is written, and the
 * field has to be absent from the values. A removal is likewise an absence,
 * named in the update mask when the write is a merge.
 *
 * Sentinels are recognised at the top level of a record only.
 *
 * @internal
 */
final readonly class FirestoreWrite
{
    /**
     * @param array<string, array<string, mixed>> $fields
     * @param list<array<string, mixed>> $transforms
     * @param list<string> $removed
     * @param list<string> $touched every field the caller mentioned, removals included
     */
    private function __construct(
        public array $fields,
        public array $transforms,
        public array $removed,
        public array $touched,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data, ValueEncoder $encoder): self
    {
        $fields = [];
        $transforms = [];
        $removed = [];

        foreach ($data as $name => $value) {
            if ($value === Sentinel::ServerTimestamp) {
                $transforms[] = ['fieldPath' => $name, 'setToServerValue' => 'REQUEST_TIME'];

                continue;
            }

            if ($value === Sentinel::Remove) {
                $removed[] = $name;

                continue;
            }

            $fields[$name] = $encoder->encode($value);
        }

        return new self($fields, $transforms, $removed, array_map(strval(...), array_keys($data)));
    }

    /**
     * Whether this write needs the commit endpoint. A transform cannot be
     * expressed against the plain document endpoint.
     */
    public function needsCommit(): bool
    {
        return $this->transforms !== [];
    }

    /**
     * The values, in a form that survives JSON encoding.
     *
     * PHP encodes an empty array as `[]`, and Firestore rejects that where it
     * expects a map: "Cannot bind a list to map for field 'fields'". Every
     * field being a sentinel is an ordinary thing to write - touching only a
     * timestamp, or removing a single field - so this is the normal case, not
     * an edge one.
     *
     * @return array<string, array<string, mixed>>|object
     */
    public function jsonFields(): array|object
    {
        return $this->fields === [] ? new \stdClass() : $this->fields;
    }
}
