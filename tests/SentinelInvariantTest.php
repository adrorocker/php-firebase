<?php

declare(strict_types=1);

namespace PhpFirebase\Tests;

use PhpFirebase\Database\Firestore\FirestoreConnection;
use PhpFirebase\Database\Firestore\FirestoreWrite;
use PhpFirebase\Database\RealtimeDatabaseConnection;
use PhpFirebase\Database\RealtimeWrite;
use PhpFirebase\Database\Sentinel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Sentinel resolution used to be a step each write path had to remember, and
 * four of them did while a fifth did not. These tests hold the structure that
 * replaced that arrangement: resolved data is a type you cannot construct
 * without resolving, and the methods that reach a database demand it.
 */
#[CoversClass(RealtimeWrite::class)]
#[CoversClass(FirestoreWrite::class)]
final class SentinelInvariantTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function writeTypes(): iterable
    {
        yield 'realtime database' => [RealtimeWrite::class];
        yield 'firestore' => [FirestoreWrite::class];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('writeTypes')]
    public function resolved_data_cannot_be_constructed_directly(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isPrivate(),
            $class.' must not be constructible directly, or resolution could be bypassed.',
        );
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function writeMethods(): iterable
    {
        yield 'realtime set' => [RealtimeDatabaseConnection::class, 'set'];
        yield 'firestore document write' => [FirestoreConnection::class, 'documentWrite'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('writeMethods')]
    public function the_methods_that_reach_a_database_demand_resolved_data(string $class, string $method): void
    {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();

        $types = array_map(
            static fn (\ReflectionParameter $p): string => $p->getType() instanceof ReflectionNamedType
                ? $p->getType()->getName()
                : '',
            $parameters,
        );

        $this->assertTrue(
            in_array(RealtimeWrite::class, $types, true) || in_array(FirestoreWrite::class, $types, true),
            sprintf('%s::%s() must take a resolved write, not a bare array.', $class, $method),
        );
    }

    #[Test]
    public function resolving_replaces_the_realtime_sentinels(): void
    {
        $write = RealtimeWrite::resolve([
            'touched' => Sentinel::ServerTimestamp,
            'gone' => Sentinel::Remove,
            'kept' => 'as is',
        ]);

        $this->assertSame(['.sv' => 'timestamp'], $write->values['touched']);
        $this->assertNull($write->values['gone']);
        $this->assertSame('as is', $write->values['kept']);
    }

    #[Test]
    public function encoding_splits_the_firestore_sentinels_out_of_the_values(): void
    {
        $write = FirestoreWrite::encode([
            'touched' => Sentinel::ServerTimestamp,
            'gone' => Sentinel::Remove,
            'kept' => 'as is',
        ], new \PhpFirebase\Database\Firestore\ValueEncoder());

        $this->assertSame(['kept' => ['stringValue' => 'as is']], $write->fields);
        $this->assertSame([['fieldPath' => 'touched', 'setToServerValue' => 'REQUEST_TIME']], $write->transforms);
        $this->assertSame(['gone'], $write->removed);
        // The mask has to name everything the caller mentioned, or a removal
        // would simply be left alone instead of removed.
        $this->assertSame(['touched', 'gone', 'kept'], $write->touched);
        $this->assertTrue($write->needsCommit());
    }

    #[Test]
    public function a_record_without_sentinels_needs_no_commit(): void
    {
        $write = FirestoreWrite::encode(['kept' => 'as is'], new \PhpFirebase\Database\Firestore\ValueEncoder());

        $this->assertFalse($write->needsCommit());
        $this->assertSame([], $write->transforms);
    }
}
