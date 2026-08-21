<?php

declare(strict_types=1);

namespace PhpFirebase\Tests\Firestore;

use PhpFirebase\Database\Firestore\ValueEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueEncoder::class)]
final class ValueEncoderTest extends TestCase
{
    private ValueEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new ValueEncoder();
    }

    #[Test]
    public function it_tags_each_scalar_with_its_type(): void
    {
        $this->assertSame(['nullValue' => null], $this->encoder->encode(null));
        $this->assertSame(['booleanValue' => true], $this->encoder->encode(true));
        $this->assertSame(['stringValue' => 'Ada'], $this->encoder->encode('Ada'));
        $this->assertSame(['doubleValue' => 1.5], $this->encoder->encode(1.5));
    }

    #[Test]
    public function integers_travel_as_strings(): void
    {
        // Firestore does this so 64-bit values survive JSON intact.
        $this->assertSame(['integerValue' => '36'], $this->encoder->encode(36));
        $this->assertSame(36, $this->encoder->decode(['integerValue' => '36']));
    }

    #[Test]
    public function a_large_integer_survives_the_round_trip(): void
    {
        $large = PHP_INT_MAX;

        $this->assertSame($large, $this->encoder->decode($this->encoder->encode($large)));
    }

    #[Test]
    public function a_list_becomes_an_array_value(): void
    {
        $this->assertSame(
            ['arrayValue' => ['values' => [['stringValue' => 'a'], ['stringValue' => 'b']]]],
            $this->encoder->encode(['a', 'b']),
        );
    }

    #[Test]
    public function a_map_becomes_a_map_value(): void
    {
        $this->assertSame(
            ['mapValue' => ['fields' => ['city' => ['stringValue' => 'London']]]],
            $this->encoder->encode(['city' => 'London']),
        );
    }

    #[Test]
    public function an_empty_array_is_stored_as_an_empty_list(): void
    {
        $this->assertSame(['arrayValue' => ['values' => []]], $this->encoder->encode([]));
        $this->assertSame([], $this->encoder->decode(['arrayValue' => ['values' => []]]));
    }

    #[Test]
    public function firestore_omits_values_for_an_empty_array(): void
    {
        $this->assertSame([], $this->encoder->decode(['arrayValue' => []]));
    }

    #[Test]
    public function a_timestamp_is_read_as_its_string_form(): void
    {
        // Which is what the entity hydrator then turns into a date.
        $this->assertSame(
            '2024-03-01T10:00:00Z',
            $this->encoder->decode(['timestampValue' => '2024-03-01T10:00:00Z']),
        );
    }

    #[Test]
    public function nested_records_round_trip(): void
    {
        $record = [
            'firstName' => 'Ada',
            'age' => 36,
            'tags' => ['maths', 'engines'],
            'address' => ['city' => 'London', 'postcode' => 'N1'],
            'active' => true,
            'score' => 9.5,
        ];

        $this->assertSame($record, $this->encoder->decodeFields($this->encoder->encodeFields($record)));
    }
}
