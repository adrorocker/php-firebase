<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use PhpFirebase\Database\Sentinel;
use PhpFirebase\Exception\InvalidEntity;

/**
 * Translates between plain PHP values and Firestore's typed value format.
 *
 * Firestore does not store bare JSON: every value is tagged with its type, and
 * integers travel as strings so that 64-bit values survive JSON. So the record
 * `['name' => 'Ada', 'age' => 36]` is stored as:
 *
 *     ['name' => ['stringValue' => 'Ada'], 'age' => ['integerValue' => '36']]
 *
 * @internal
 */
class ValueEncoder
{
    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, array<string, mixed>>
     */
    public function encodeFields(array $fields): array
    {
        $encoded = [];

        foreach ($fields as $name => $value) {
            if ($value instanceof Sentinel) {
                throw new InvalidEntity(sprintf(
                    'The %s sentinel cannot be encoded as a value; it has to be resolved by the connection.',
                    $value->name,
                ));
            }

            $encoded[$name] = $this->encode($value);
        }

        return $encoded;
    }


    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function decodeFields(array $fields): array
    {
        $decoded = [];

        foreach ($fields as $name => $value) {
            $decoded[$name] = is_array($value) ? $this->decode($value) : null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(mixed $value): array
    {
        return match (true) {
            $value === null => ['nullValue' => null],
            is_bool($value) => ['booleanValue' => $value],
            // Firestore takes and returns integers as strings.
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_string($value) => ['stringValue' => $value],
            is_array($value) => $this->encodeArray($value),
            default => throw new InvalidEntity(sprintf(
                'Cannot store a value of type %s in Firestore.',
                get_debug_type($value),
            )),
        };
    }

    /**
     * @param array<string, mixed> $value
     */
    public function decode(array $value): mixed
    {
        // A Firestore value is a single-key map naming its type.
        return match (true) {
            array_key_exists('nullValue', $value) => null,
            array_key_exists('booleanValue', $value) => (bool) $value['booleanValue'],
            array_key_exists('integerValue', $value) => (int) $value['integerValue'],
            array_key_exists('doubleValue', $value) => (float) $value['doubleValue'],
            array_key_exists('timestampValue', $value) => (string) $value['timestampValue'],
            array_key_exists('stringValue', $value) => (string) $value['stringValue'],
            array_key_exists('bytesValue', $value) => (string) $value['bytesValue'],
            array_key_exists('referenceValue', $value) => (string) $value['referenceValue'],
            array_key_exists('geoPointValue', $value) => $value['geoPointValue'],
            array_key_exists('arrayValue', $value) => $this->decodeArray($value['arrayValue']),
            array_key_exists('mapValue', $value) => $this->decodeMap($value['mapValue']),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function encodeArray(array $value): array
    {
        // A list becomes an arrayValue and anything else a mapValue, which is
        // also why an empty array is stored as an empty list.
        if (array_is_list($value)) {
            return ['arrayValue' => ['values' => array_map($this->encode(...), $value)]];
        }

        /** @var array<string, mixed> $value */
        return ['mapValue' => ['fields' => $this->encodeFields($value)]];
    }

    /**
     * @return list<mixed>
     */
    private function decodeArray(mixed $arrayValue): array
    {
        if (!is_array($arrayValue) || !isset($arrayValue['values']) || !is_array($arrayValue['values'])) {
            // Firestore omits "values" entirely for an empty array.
            return [];
        }

        $values = [];

        foreach ($arrayValue['values'] as $value) {
            $values[] = is_array($value) ? $this->decode($value) : null;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMap(mixed $mapValue): array
    {
        if (!is_array($mapValue) || !isset($mapValue['fields']) || !is_array($mapValue['fields'])) {
            return [];
        }

        /** @var array<string, mixed> $fields */
        $fields = $mapValue['fields'];

        return $this->decodeFields($fields);
    }
}
