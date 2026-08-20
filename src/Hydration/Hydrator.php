<?php

declare(strict_types=1);

namespace PhpFirebase\Hydration;

use BackedEnum;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use PhpFirebase\Database\Sentinel;
use PhpFirebase\EntityInterface;
use PhpFirebase\Exception\InvalidEntity;
use ReflectionClass;
use ReflectionProperty;

/**
 * Maps entities to plain arrays and back.
 *
 * Reading is delegated to cuyz/valinor, which already understands enums, dates,
 * nested objects and union types better than anything worth hand-rolling here.
 *
 * Writing is not, because the two rules this library stores records by are not
 * valinor's defaults: private properties are never persisted, and null values
 * are left out entirely. The first is how {@see \PhpFirebase\Entity} keeps its
 * own `$id` out of the payload - in a database the key *is* the id, so writing
 * it into the value as well only lets the two drift apart. The second matters
 * because the Realtime Database deletes any key written as null.
 *
 * @internal
 */
final class Hydrator
{
    private const int PERSISTED_MODIFIERS = ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED;

    /**
     * A record nested this deeply is a cycle in all but name, and failing here
     * beats exhausting memory.
     */
    private const int MAX_DEPTH = 32;

    private static ?TreeMapper $mapper = null;

    /**
     * Classes already checked for a usable constructor.
     *
     * @var array<class-string, true>
     */
    private static array $checked = [];

    /**
     * @return array<string, mixed>
     */
    public function extract(object $entity): array
    {
        return $this->extractObject($entity, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractObject(object $object, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidEntity(sprintf(
                'Giving up %d levels deep in %s. Records this deeply nested are usually a cycle.',
                self::MAX_DEPTH,
                $object::class,
            ));
        }

        $data = [];

        foreach ($this->properties($object::class) as $property) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);

            if ($value === null) {
                continue;
            }

            $data[$property->getName()] = $this->extractValue($value, $depth);
        }

        return $data;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     *
     * @return T
     */
    public function hydrate(string $class, array $data): object
    {
        $this->guardConstructor($class);

        try {
            return self::mapper()->map($class, $data);
        } catch (MappingError $error) {
            throw InvalidEntity::mappingFailed($class, $error);
        }
    }

    /**
     * Valinor builds an object through its constructor when it has one, and
     * fills its properties directly when it does not. Those two rules are both
     * fine; what is not fine is a constructor that covers only some of the
     * persisted properties, because the rest are then quietly skipped. That
     * would lose data on read and overwrite it on the next write, so it is
     * refused here instead.
     *
     * @param class-string $class
     */
    private function guardConstructor(string $class): void
    {
        if (isset(self::$checked[$class])) {
            return;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor !== null) {
            if (!$constructor->isPublic()) {
                throw InvalidEntity::constructorNotPublic($class);
            }

            $parameters = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            );

            $uncovered = [];

            foreach ($this->properties($class) as $property) {
                if (!in_array($property->getName(), $parameters, true)) {
                    $uncovered[] = $property->getName();
                }
            }

            if ($uncovered !== []) {
                throw InvalidEntity::partiallyConstructed($class, $uncovered);
            }
        }

        self::$checked[$class] = true;
    }

    private static function mapper(): TreeMapper
    {
        // Building a mapper is expensive and the configuration never varies,
        // so one is shared for the lifetime of the process.
        return self::$mapper ??= (new MapperBuilder())
            // Stored records routinely carry fields an entity no longer
            // declares, and lack fields it has since gained.
            ->allowSuperfluousKeys()
            ->allowUndefinedValues()
            // Records come back from a schemaless database, so an untyped
            // `array` property is a normal thing to declare rather than an
            // error. Adding a `@var list<T>` docblock buys validation back.
            ->allowPermissiveTypes()
            // JSON round-trips numbers loosely, so an int-backed enum can come
            // back as 2.0 and an int property as "36".
            ->allowScalarValueCasting()
            // Registering a constructor replaces valinor's built-in ones, so
            // every form a date arrives in has to be spelled out. Scalar
            // casting means an integer may reach the string constructor, so
            // both agree on what a bare number means.
            ->registerConstructor(
                static fn (int $milliseconds): DateTimeImmutable => self::fromMilliseconds($milliseconds),
                static fn (string $value): DateTimeImmutable => self::parseDate($value),
            )
            ->mapper();
    }

    /**
     * Firebase resolves a server timestamp to Unix milliseconds, which is not
     * how any date parser reads a bare number by default.
     *
     * @pure
     */
    private static function fromMilliseconds(int $milliseconds): DateTimeImmutable
    {
        // Deterministic despite the checker's doubt: an "@epoch" string and a
        // fixed zone never consult the clock. Valinor requires the constructor
        // to be pure, so the annotation has to stay.
        /** @phpstan-ignore possiblyImpure.new, possiblyImpure.new */
        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @pure
     */
    private static function parseDate(string $value): DateTimeImmutable
    {
        // A run of digits long enough to be an epoch is treated as one; a
        // shorter one is a date like "20240301" and is left to the parser.
        if (ctype_digit($value) && strlen($value) >= 12) {
            return self::fromMilliseconds((int) $value);
        }

        // Absolute date strings only; the value comes from stored data, never
        // from a relative expression like "now".
        /** @phpstan-ignore possiblyImpure.new */
        return new DateTimeImmutable($value);
    }

    /**
     * Objects of PHP's own classes have no properties worth storing, and
     * reading them would quietly produce an empty record rather than an error.
     * Anything declared in userland is fair game.
     */
    private function guardStorable(object $value): object
    {
        if ((new ReflectionClass($value))->isInternal()) {
            throw new InvalidEntity(sprintf(
                'Cannot store a %s. Convert it before saving, or implement %s on the object holding it.',
                $value::class,
                JsonSerializable::class,
            ));
        }

        return $value;
    }

    /**
     * @param class-string $class
     *
     * @return list<ReflectionProperty>
     */
    private function properties(string $class): array
    {
        $properties = [];

        foreach ((new ReflectionClass($class))->getProperties(self::PERSISTED_MODIFIERS) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[] = $property;
        }

        return $properties;
    }

    private function extractValue(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            // A sentinel is resolved by the connection, not here, so it travels
            // through the mapper untouched.
            $value instanceof Sentinel => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof EntityInterface => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            // Any other object is read the same way an entity is, so a plain
            // value object round-trips: valinor can already build one on the
            // way in, and this is what lets it back out again.
            is_object($value) => $this->extractObject($this->guardStorable($value), $depth + 1),
            is_array($value) => array_map(
                fn (mixed $item): mixed => $this->extractValue($item, $depth + 1),
                $value,
            ),
            is_scalar($value) => $value,
            default => throw new InvalidEntity(sprintf(
                'Cannot store a value of type %s.',
                get_debug_type($value),
            )),
        };
    }
}
