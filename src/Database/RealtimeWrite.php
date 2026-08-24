<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

use Kreait\Firebase\Contract\Database;

/**
 * Data that has been through sentinel resolution and is safe to send.
 *
 * The constructor is private and {@see resolve()} is the only way to obtain
 * one, so a value of this type cannot exist without having been resolved.
 * Every method in {@see RealtimeDatabaseConnection} that reaches the database
 * demands one, which is what stops a new write path from quietly skipping the
 * step - the compiler refuses it rather than the database misbehaving later.
 *
 * This exists because forgetting used to be easy: sentinels needed resolving
 * in four separate places, and each new write path was another chance to miss
 * one. A pure enum reaching kreait produces "Non-backed enums have no default
 * serialization", which kreait then reports as a failed transaction - an error
 * that says nothing about the actual cause.
 *
 * @internal
 */
final readonly class RealtimeWrite
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(public array $values)
    {
    }

    /**
     * Turns sentinels into what the Realtime Database understands: its own
     * server-value marker for the clock, and null for a removal, since the
     * database deletes any key written as null.
     *
     * @param array<string, mixed> $data
     */
    public static function resolve(array $data): self
    {
        foreach ($data as $name => $value) {
            if ($value === Sentinel::ServerTimestamp) {
                $data[$name] = Database::SERVER_TIMESTAMP;
            } elseif ($value === Sentinel::Remove) {
                $data[$name] = null;
            }
        }

        return new self($data);
    }

    /**
     * @param array<string, array<string, mixed>> $records
     *
     * @return array<string, array<string, mixed>>
     */
    public static function resolveAll(array $records): array
    {
        return array_map(static fn (array $record): array => self::resolve($record)->values, $records);
    }
}
